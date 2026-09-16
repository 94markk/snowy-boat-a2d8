package com.delicat.studio

import android.content.Context
import android.media.MediaCodec
import android.media.MediaCodecInfo
import android.media.MediaFormat
import android.media.MediaMuxer
import android.opengl.GLES20
import android.view.Surface
import com.delicat.studio.engine.export.EglCore
import com.delicat.studio.engine.gl.GlUtil
import java.io.File

/**
 * Makes a real video file to test against.
 *
 * Shipping a sample clip in the repository would mean a binary nobody can
 * review and a decoder path that only ever sees one encoder's output.
 * Synthesising it here means the test encodes with the same MediaCodec the
 * device decodes with, and the colours are known exactly, so a frame can be
 * checked rather than merely counted.
 */
object TestMedia {

    /** Writes [seconds] of solid colour to an MP4 and returns the file. */
    fun writeVideo(
        context: Context,
        width: Int = 320,
        height: Int = 240,
        seconds: Float = 1f,
        fps: Int = 15,
        colour: Int = 0xFF3C78D8.toInt(),
    ): File {
        val file = File(context.cacheDir, "probe-${System.nanoTime()}.mp4")
        val encoder = MediaCodec.createEncoderByType("video/avc")
        val format = MediaFormat.createVideoFormat("video/avc", width, height).apply {
            setInteger(
                MediaFormat.KEY_COLOR_FORMAT,
                MediaCodecInfo.CodecCapabilities.COLOR_FormatSurface,
            )
            setInteger(MediaFormat.KEY_BIT_RATE, 1_500_000)
            setInteger(MediaFormat.KEY_FRAME_RATE, fps)
            setInteger(MediaFormat.KEY_I_FRAME_INTERVAL, 1)
        }
        encoder.configure(format, null, null, MediaCodec.CONFIGURE_FLAG_ENCODE)
        val input: Surface = encoder.createInputSurface()
        encoder.start()

        val egl = EglCore()
        val eglSurface = egl.createWindowSurface(input)
        egl.makeCurrent(eglSurface)

        val muxer = MediaMuxer(file.absolutePath, MediaMuxer.OutputFormat.MUXER_OUTPUT_MPEG_4)
        val info = MediaCodec.BufferInfo()
        var track = -1
        var muxing = false

        fun drain(end: Boolean) {
            while (true) {
                val index = encoder.dequeueOutputBuffer(info, if (end) 10_000L else 0L)
                if (index == MediaCodec.INFO_TRY_AGAIN_LATER) {
                    if (!end) return else continue
                }
                if (index == MediaCodec.INFO_OUTPUT_FORMAT_CHANGED) {
                    track = muxer.addTrack(encoder.outputFormat)
                    muxer.start()
                    muxing = true
                    continue
                }
                if (index < 0) continue
                val buffer = encoder.getOutputBuffer(index)
                if (info.flags and MediaCodec.BUFFER_FLAG_CODEC_CONFIG != 0) info.size = 0
                if (info.size > 0 && buffer != null && muxing) {
                    buffer.position(info.offset)
                    buffer.limit(info.offset + info.size)
                    muxer.writeSampleData(track, buffer, info)
                }
                encoder.releaseOutputBuffer(index, false)
                if (info.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM != 0) return
            }
        }

        val frames = (seconds * fps).toInt().coerceAtLeast(2)
        val frameUs = 1_000_000L / fps
        for (frame in 0 until frames) {
            GLES20.glViewport(0, 0, width, height)
            GLES20.glClearColor(
                ((colour shr 16) and 0xFF) / 255f,
                ((colour shr 8) and 0xFF) / 255f,
                (colour and 0xFF) / 255f,
                1f,
            )
            GLES20.glClear(GLES20.GL_COLOR_BUFFER_BIT)
            GlUtil.checkGl("test frame")
            egl.setPresentationTime(eglSurface, frame * frameUs * 1000L)
            egl.swapBuffers(eglSurface)
            drain(false)
        }

        encoder.signalEndOfInputStream()
        drain(true)

        egl.releaseSurface(eglSurface)
        egl.release()
        runCatching { encoder.stop() }
        encoder.release()
        runCatching { muxer.stop() }
        muxer.release()
        return file
    }
}
