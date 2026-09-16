package com.vixel.studio.engine.audio

import android.content.Context
import android.media.MediaRecorder
import android.os.Build
import android.util.Log
import java.io.File

private const val TAG = "VixelVoice"

/**
 * Records a voiceover to AAC in an MP4 container, which the mixer can decode
 * like any other source.
 *
 * One recording at a time; [stop] returns the finished file.
 */
class VoiceRecorder(private val context: Context) {

    private var recorder: MediaRecorder? = null
    private var target: File? = null

    val isRecording: Boolean get() = recorder != null

    fun start(): Boolean {
        if (recorder != null) return false

        val dir = File(context.filesDir, "voiceover").apply { mkdirs() }
        val file = File(dir, "vo_${System.currentTimeMillis()}.m4a")

        return try {
            val instance = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                MediaRecorder(context)
            } else {
                @Suppress("DEPRECATION")
                MediaRecorder()
            }
            instance.apply {
                setAudioSource(MediaRecorder.AudioSource.MIC)
                setOutputFormat(MediaRecorder.OutputFormat.MPEG_4)
                setAudioEncoder(MediaRecorder.AudioEncoder.AAC)
                // Match the mixer's canonical rate so the voiceover needs no
                // resampling on the way in.
                setAudioSamplingRate(PcmDecoder.SAMPLE_RATE)
                setAudioChannels(1)
                setAudioEncodingBitRate(128_000)
                setOutputFile(file.absolutePath)
                prepare()
                start()
            }
            recorder = instance
            target = file
            true
        } catch (t: Throwable) {
            Log.e(TAG, "could not start recording", t)
            runCatching { recorder?.release() }
            recorder = null
            target = null
            file.delete()
            false
        }
    }

    /** @return the recorded file, or null if nothing usable was captured. */
    fun stop(): File? {
        val instance = recorder ?: return null
        val file = target
        recorder = null
        target = null

        return try {
            instance.stop()
            instance.release()
            file?.takeIf { it.exists() && it.length() > 0 }
        } catch (t: Throwable) {
            // stop() throws when it is called before any frames were written,
            // which happens on a tap-and-release. The partial file is useless.
            Log.w(TAG, "recording stopped without usable audio", t)
            runCatching { instance.release() }
            file?.delete()
            null
        }
    }

    fun cancel() {
        val file = target
        stop()
        file?.delete()
    }
}
