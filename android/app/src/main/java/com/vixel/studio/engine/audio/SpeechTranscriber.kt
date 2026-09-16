package com.vixel.studio.engine.audio

import android.content.Context
import android.content.Intent
import android.media.AudioFormat
import android.os.Build
import android.os.Bundle
import android.os.ParcelFileDescriptor
import android.speech.RecognitionListener
import android.speech.RecognizerIntent
import android.speech.SpeechRecognizer
import android.util.Log
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import java.io.File
import java.io.RandomAccessFile
import java.util.concurrent.atomic.AtomicBoolean
import kotlin.coroutines.resume

private const val TAG = "VixelSpeech"

/**
 * Transcribes PCM with the platform's on-device recogniser.
 *
 * Since API 31 the recogniser can take a file descriptor instead of the
 * microphone, so audio already on disk can be fed straight in. That is what
 * makes captions possible here without bundling a model or calling a service —
 * the app still makes no network requests.
 *
 * Recognition happens on device only. The networked recogniser would work too
 * and often better, but it would send the user's audio to a server, which is
 * not something an offline editor should start doing quietly.
 */
object SpeechTranscriber {

    /** What the recogniser is fed. 16 kHz mono is what speech models expect. */
    const val SAMPLE_RATE = 16_000

    private const val PER_UTTERANCE_TIMEOUT_MS = 20_000L

    /** Feeding audio from a descriptor arrived in API 31. */
    val isSupported: Boolean get() = Build.VERSION.SDK_INT >= Build.VERSION_CODES.S

    /**
     * Whether a language pack is installed and ready.
     *
     * A device can support the API but have no downloaded model, in which case
     * every request fails; checking first lets the UI say so plainly instead of
     * producing empty captions.
     */
    fun isAvailable(context: Context): Boolean {
        if (!isSupported) return false
        return try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                SpeechRecognizer.isOnDeviceRecognitionAvailable(context)
            } else {
                SpeechRecognizer.isRecognitionAvailable(context)
            }
        } catch (t: Throwable) {
            false
        }
    }

    /**
     * Recognises one utterance out of [pcm] (16 kHz mono 16-bit).
     *
     * @return the transcript, or null when nothing was recognised.
     */
    suspend fun transcribe(
        context: Context,
        pcm: File,
        utterance: Utterance,
        languageTag: String = java.util.Locale.getDefault().toLanguageTag(),
    ): String? {
        if (!isSupported) return null
        val frames = utterance.lengthFrames
        if (frames <= 0) return null

        return withTimeoutOrNull(PER_UTTERANCE_TIMEOUT_MS) {
            // SpeechRecognizer must be created and driven from the main looper.
            withContext(Dispatchers.Main) {
                runOnce(context, pcm, utterance, languageTag)
            }
        }
    }

    private suspend fun runOnce(
        context: Context,
        pcm: File,
        utterance: Utterance,
        languageTag: String,
    ): String? = suspendCancellableCoroutine { continuation ->
        var recognizer: SpeechRecognizer? = null
        val settled = AtomicBoolean(false)

        fun finish(result: String?) {
            if (!settled.compareAndSet(false, true)) return
            runCatching { recognizer?.destroy() }
            continuation.resume(result)
        }

        try {
            recognizer = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                SpeechRecognizer.createOnDeviceSpeechRecognizer(context)
            } else {
                SpeechRecognizer.createSpeechRecognizer(context)
            }

            val pipe = ParcelFileDescriptor.createPipe()
            val readSide = pipe[0]
            val writeSide = pipe[1]

            val intent = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).apply {
                putExtra(
                    RecognizerIntent.EXTRA_LANGUAGE_MODEL,
                    RecognizerIntent.LANGUAGE_MODEL_FREE_FORM,
                )
                putExtra(RecognizerIntent.EXTRA_LANGUAGE, languageTag)
                putExtra(RecognizerIntent.EXTRA_PREFER_OFFLINE, true)
                putExtra(RecognizerIntent.EXTRA_AUDIO_SOURCE, readSide)
                putExtra(
                    RecognizerIntent.EXTRA_AUDIO_SOURCE_ENCODING,
                    AudioFormat.ENCODING_PCM_16BIT,
                )
                putExtra(RecognizerIntent.EXTRA_AUDIO_SOURCE_SAMPLING_RATE, SAMPLE_RATE)
                putExtra(RecognizerIntent.EXTRA_AUDIO_SOURCE_CHANNEL_COUNT, 1)
            }

            recognizer.setRecognitionListener(
                object : RecognitionListener {
                    override fun onResults(results: Bundle?) {
                        val text = results
                            ?.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION)
                            ?.firstOrNull()
                            ?.trim()
                        finish(text?.takeIf { it.isNotEmpty() })
                    }

                    override fun onError(error: Int) {
                        // NO_MATCH just means this stretch had no words in it,
                        // which is ordinary for a segment of music or noise.
                        if (error != SpeechRecognizer.ERROR_NO_MATCH) {
                            Log.w(TAG, "recognition error $error")
                        }
                        finish(null)
                    }

                    override fun onReadyForSpeech(params: Bundle?) = Unit
                    override fun onBeginningOfSpeech() = Unit
                    override fun onRmsChanged(rmsdB: Float) = Unit
                    override fun onBufferReceived(buffer: ByteArray?) = Unit
                    override fun onEndOfSpeech() = Unit
                    override fun onPartialResults(partialResults: Bundle?) = Unit
                    override fun onEvent(eventType: Int, params: Bundle?) = Unit
                },
            )

            recognizer.startListening(intent)

            // Pump the segment into the pipe off the main thread; the writer
            // must close so the recogniser sees end of stream and reports.
            Thread {
                runCatching {
                    ParcelFileDescriptor.AutoCloseOutputStream(writeSide).use { out ->
                        RandomAccessFile(pcm, "r").use { raf ->
                            raf.seek(utterance.startFrame * 2)
                            var remaining = utterance.lengthFrames * 2
                            val chunk = ByteArray(8192)
                            while (remaining > 0) {
                                val want = minOf(chunk.size.toLong(), remaining).toInt()
                                val read = raf.read(chunk, 0, want)
                                if (read <= 0) break
                                out.write(chunk, 0, read)
                                remaining -= read
                            }
                            out.flush()
                        }
                    }
                }.onFailure { Log.w(TAG, "could not feed audio to the recogniser", it) }
                runCatching { readSide.close() }
            }.also { it.isDaemon = true }.start()

            continuation.invokeOnCancellation { finish(null) }
        } catch (t: Throwable) {
            Log.e(TAG, "could not start recognition", t)
            finish(null)
        }
    }
}
