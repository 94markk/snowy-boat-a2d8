package com.vixel.studio.engine.video

import android.content.Context
import android.graphics.Bitmap
import android.media.MediaCodec
import android.media.MediaCodecInfo
import android.media.MediaExtractor
import android.media.MediaFormat
import android.media.MediaMuxer
import android.net.Uri
import android.opengl.GLES30
import android.opengl.Matrix
import android.util.Log
import com.vixel.studio.core.io.ImageIo
import com.vixel.studio.engine.audio.AacEncoder
import com.vixel.studio.engine.audio.AudioMixer
import com.vixel.studio.engine.audio.MixSource
import com.vixel.studio.engine.audio.PcmDecoder
import com.vixel.studio.core.model.Clip
import com.vixel.studio.core.model.FitMode
import com.vixel.studio.core.model.MediaKind
import com.vixel.studio.core.model.Project
import com.vixel.studio.engine.gl.ColorGrader
import com.vixel.studio.engine.gl.EglCore
import com.vixel.studio.engine.gl.GlUtils
import java.io.File
import java.nio.ByteBuffer
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt
import kotlin.math.roundToLong

private const val TAG = "VixelExport"

data class ExportConfig(
    /** Short edge in pixels: 720, 1080, 1440 or 2160. */
    val shortEdge: Int = 1080,
    val fps: Int = 30,
    /** Null lets the exporter pick from resolution and frame rate. */
    val bitRate: Int? = null,
    val includeAudio: Boolean = true,
) {
    fun resolvedBitRate(width: Int, height: Int): Int =
        bitRate ?: run {
            // ~0.12 bits per pixel per frame is a solid quality/size tradeoff
            // for H.264 at social-media resolutions.
            val bpp = 0.12
            (width * height * fps * bpp).toInt().coerceIn(2_000_000, 48_000_000)
        }
}

sealed interface ExportResult {
    data class Success(
        val file: File,
        val uri: Uri?,
        val durationUs: Long,
        val warning: String? = null,
    ) : ExportResult
    data class Failure(val message: String, val cause: Throwable? = null) : ExportResult
}

/**
 * Renders a [Project] to an MP4.
 *
 * Every clip is decoded to an OES texture, put through the same [ColorGrader]
 * the preview uses, and drawn straight onto the encoder's input surface — so
 * the exported file matches the preview by construction rather than by two
 * implementations agreeing.
 *
 * Call [export] from a background thread.
 */
class VideoExporter(
    private val context: Context,
    private val project: Project,
    private val config: ExportConfig = ExportConfig(),
) {

    @Volatile
    private var cancelled = false

    fun cancel() {
        cancelled = true
    }

    /**
     * Renders the project to [outputFile] and publishes it to the gallery.
     *
     * Video and audio are produced as separate passes and joined at the end;
     * see [Remuxer] for why.
     */
    fun export(outputFile: File, onProgress: (Float) -> Unit = {}): ExportResult {
        if (project.clips.isEmpty()) {
            return ExportResult.Failure("There is nothing on the timeline to export")
        }

        val workDir = File(outputFile.parentFile ?: context.cacheDir, "work-${System.currentTimeMillis()}")
        workDir.mkdirs()
        val tempVideo = File(workDir, "video.mp4")

        try {
            renderVideo(tempVideo) { p -> onProgress(p * VIDEO_SHARE) }
                ?.let { return it }

            if (cancelled) return ExportResult.Failure("Export cancelled")

            val audioFile = if (config.includeAudio) {
                buildAudioTrack(workDir) { p -> onProgress(VIDEO_SHARE + p * AUDIO_SHARE) }
            } else {
                null
            }

            var warning: String? = null
            val joined = if (audioFile != null) {
                Remuxer.combine(tempVideo, audioFile, outputFile).also { ok ->
                    if (!ok) warning = "Audio could not be attached; exported without sound"
                }
            } else {
                false
            }

            if (!joined) {
                tempVideo.copyTo(outputFile, overwrite = true)
            }

            onProgress(1f)
            val uri = runCatching { VideoIo.publishToGallery(context, outputFile) }.getOrNull()
            return ExportResult.Success(outputFile, uri, project.durationUs, warning)
        } catch (t: Throwable) {
            Log.e(TAG, "export failed", t)
            runCatching { outputFile.delete() }
            return ExportResult.Failure(t.message ?: "Export failed", t)
        } finally {
            runCatching { workDir.deleteRecursively() }
        }
    }

    /**
     * Decodes every audible source to PCM, mixes them onto one timeline and
     * encodes the result to AAC.
     *
     * @return the encoded m4a, or null when the project is silent.
     */
    private fun buildAudioTrack(workDir: File, onProgress: (Float) -> Unit): File? {
        val sources = mutableListOf<MixSource>()
        var index = 0
        var timelineUs = 0L

        // Audio that rides along with the video clips.
        for (clip in project.clips) {
            val clipStartUs = timelineUs
            timelineUs += clip.timelineDurationUs
            if (clip.kind != MediaKind.VIDEO || clip.muted || clip.volume <= 0f) continue

            val pcm = File(workDir, "clip_${index++}.pcm")
            val frames = PcmDecoder.decode(
                context = context,
                uri = Uri.parse(clip.uri),
                startUs = clip.trimStartUs,
                endUs = clip.trimEndUs,
                output = pcm,
                speed = clip.speed,
            )
            if (frames <= 0) {
                pcm.delete()
                continue
            }
            sources += MixSource(
                pcm = pcm,
                startFrame = PcmDecoder.usToFrames(clipStartUs),
                volume = clip.volume,
                fadeInFrames = PcmDecoder.usToFrames(clip.fadeInUs),
                fadeOutFrames = PcmDecoder.usToFrames(clip.fadeOutUs),
            )
        }
        onProgress(0.5f)

        // Music and voiceover tracks.
        for (audio in project.audio) {
            if (audio.volume <= 0f) continue
            val pcm = File(workDir, "audio_${index++}.pcm")
            val frames = PcmDecoder.decode(
                context = context,
                uri = Uri.parse(audio.uri),
                startUs = audio.trimStartUs,
                endUs = audio.trimEndUs,
                output = pcm,
            )
            if (frames <= 0) {
                pcm.delete()
                continue
            }
            sources += MixSource(
                pcm = pcm,
                startFrame = PcmDecoder.usToFrames(audio.startOnTimelineUs),
                volume = audio.volume,
                fadeInFrames = PcmDecoder.usToFrames(audio.fadeInUs),
                fadeOutFrames = PcmDecoder.usToFrames(audio.fadeOutUs),
            )
        }

        if (sources.isEmpty()) return null
        onProgress(0.7f)

        val mixed = File(workDir, "mix.pcm")
        val totalFrames = PcmDecoder.usToFrames(project.durationUs)
        if (!AudioMixer.mix(sources, totalFrames, mixed)) return null
        onProgress(0.85f)

        val encoded = File(workDir, "audio.m4a")
        if (!AacEncoder.encode(mixed, encoded)) return null
        onProgress(1f)
        return encoded
    }

    private fun renderVideo(outputFile: File, onProgress: (Float) -> Unit): ExportResult.Failure? {
        if (project.clips.isEmpty()) {
            return ExportResult.Failure("There is nothing on the timeline to export")
        }

        val (width, height) = project.aspect.sizeFor(config.shortEdge)
        val totalDurationUs = project.durationUs.coerceAtLeast(1L)

        var encoder: MediaCodec? = null
        var muxer: MediaMuxer? = null
        var egl: EglCore? = null
        var eglSurface: android.opengl.EGLSurface? = null
        val grader = ColorGrader()

        try {
            encoder = MediaCodec.createEncoderByType(MIME_VIDEO)
            val format = MediaFormat.createVideoFormat(MIME_VIDEO, width, height).apply {
                setInteger(
                    MediaFormat.KEY_COLOR_FORMAT,
                    MediaCodecInfo.CodecCapabilities.COLOR_FormatSurface,
                )
                setInteger(MediaFormat.KEY_BIT_RATE, config.resolvedBitRate(width, height))
                setInteger(MediaFormat.KEY_FRAME_RATE, config.fps)
                setInteger(MediaFormat.KEY_I_FRAME_INTERVAL, 1)
            }
            encoder.configure(format, null, null, MediaCodec.CONFIGURE_FLAG_ENCODE)

            val inputSurface = encoder.createInputSurface()
            egl = EglCore(recordable = true)
            eglSurface = egl.createWindowSurface(inputSurface)
            egl.makeCurrent(eglSurface)
            grader.init()

            encoder.start()
            muxer = MediaMuxer(outputFile.absolutePath, MediaMuxer.OutputFormat.MUXER_OUTPUT_MPEG_4)

            val state = MuxState(muxer)
            var timelineUs = 0L

            for ((index, clip) in project.clips.withIndex()) {
                if (cancelled) return ExportResult.Failure("Export cancelled")

                val clipStart = timelineUs
                when (clip.kind) {
                    MediaKind.IMAGE -> renderStillClip(
                        clip = clip,
                        grader = grader,
                        egl = egl,
                        eglSurface = eglSurface,
                        encoder = encoder,
                        state = state,
                        canvasWidth = width,
                        canvasHeight = height,
                        timelineStartUs = clipStart,
                    )
                    else -> renderVideoClip(
                        clip = clip,
                        grader = grader,
                        egl = egl,
                        eglSurface = eglSurface,
                        encoder = encoder,
                        state = state,
                        canvasWidth = width,
                        canvasHeight = height,
                        timelineStartUs = clipStart,
                    ) { sourceProgressUs ->
                        val done = clipStart + sourceProgressUs
                        onProgress((done.toFloat() / totalDurationUs).coerceIn(0f, 0.99f))
                    }
                }

                timelineUs += clip.timelineDurationUs
                onProgress((timelineUs.toFloat() / totalDurationUs).coerceIn(0f, 0.99f))
                Log.d(TAG, "clip ${index + 1}/${project.clips.size} done at ${timelineUs}us")
            }

            // Signal end of stream and flush whatever the encoder still holds.
            encoder.signalEndOfInputStream()
            drainEncoder(encoder, state, endOfStream = true)

            state.finish()
            onProgress(1f)
            return null
        } catch (t: Throwable) {
            Log.e(TAG, "export failed", t)
            runCatching { outputFile.delete() }
            return ExportResult.Failure(t.message ?: "Export failed", t)
        } finally {
            runCatching { encoder?.stop() }
            runCatching { encoder?.release() }
            runCatching { muxer?.release() }
            runCatching { grader.release() }
            if (egl != null && eglSurface != null) runCatching { egl.releaseSurface(eglSurface) }
            runCatching { egl?.release() }
        }
    }

    // ---------------------------------------------------------------- video

    private fun renderVideoClip(
        clip: Clip,
        grader: ColorGrader,
        egl: EglCore,
        eglSurface: android.opengl.EGLSurface,
        encoder: MediaCodec,
        state: MuxState,
        canvasWidth: Int,
        canvasHeight: Int,
        timelineStartUs: Long,
        onProgress: (Long) -> Unit,
    ) {
        val extractor = MediaExtractor()
        var decoder: MediaCodec? = null
        var decoderSurface: DecoderSurface? = null

        try {
            extractor.setDataSource(context, Uri.parse(clip.uri), null)
            val trackIndex = selectTrack(extractor, "video/")
            if (trackIndex < 0) {
                Log.w(TAG, "no video track in ${clip.uri}")
                return
            }
            extractor.selectTrack(trackIndex)
            val inputFormat = extractor.getTrackFormat(trackIndex)

            decoderSurface = DecoderSurface()
            decoder = MediaCodec.createDecoderByType(
                inputFormat.getString(MediaFormat.KEY_MIME) ?: return,
            )
            decoder.configure(inputFormat, decoderSurface.surface, null, 0)
            decoder.start()

            extractor.seekTo(clip.trimStartUs, MediaExtractor.SEEK_TO_PREVIOUS_SYNC)

            val bufferInfo = MediaCodec.BufferInfo()
            var inputDone = false
            var outputDone = false

            while (!outputDone && !cancelled) {
                if (!inputDone) {
                    val inputIndex = decoder.dequeueInputBuffer(TIMEOUT_US)
                    if (inputIndex >= 0) {
                        val buffer = decoder.getInputBuffer(inputIndex)
                        val sampleSize = if (buffer == null) -1 else extractor.readSampleData(buffer, 0)
                        if (sampleSize < 0) {
                            decoder.queueInputBuffer(
                                inputIndex, 0, 0, 0L, MediaCodec.BUFFER_FLAG_END_OF_STREAM,
                            )
                            inputDone = true
                        } else {
                            decoder.queueInputBuffer(
                                inputIndex, 0, sampleSize, extractor.sampleTime, 0,
                            )
                            extractor.advance()
                        }
                    }
                }

                val outputIndex = decoder.dequeueOutputBuffer(bufferInfo, TIMEOUT_US)
                when {
                    outputIndex == MediaCodec.INFO_TRY_AGAIN_LATER -> Unit
                    outputIndex == MediaCodec.INFO_OUTPUT_FORMAT_CHANGED -> Unit
                    outputIndex >= 0 -> {
                        val eos = (bufferInfo.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM) != 0
                        val ptUs = bufferInfo.presentationTimeUs

                        val keep = bufferInfo.size > 0 &&
                            ptUs >= clip.trimStartUs &&
                            ptUs <= clip.trimEndUs

                        decoder.releaseOutputBuffer(outputIndex, keep)

                        if (keep && decoderSurface.awaitNewImage()) {
                            val outUs = timelineStartUs +
                                ((ptUs - clip.trimStartUs) / clip.speed).roundToLong()

                            drawFrame(
                                grader = grader,
                                clip = clip,
                                textureId = decoderSurface.textureId,
                                texMatrix = decoderSurface.transform(),
                                canvasWidth = canvasWidth,
                                canvasHeight = canvasHeight,
                                sourceWidth = clip.displayWidth.takeIf { it > 0 } ?: canvasWidth,
                                sourceHeight = clip.displayHeight.takeIf { it > 0 } ?: canvasHeight,
                                isExternal = true,
                                timelineUs = outUs - timelineStartUs,
                            )

                            egl.setPresentationTime(eglSurface, outUs * 1000L)
                            egl.swapBuffers(eglSurface)
                            drainEncoder(encoder, state, endOfStream = false)
                            onProgress(((ptUs - clip.trimStartUs) / clip.speed).roundToLong())
                        }

                        if (eos || ptUs > clip.trimEndUs) outputDone = true
                    }
                }
            }
        } catch (t: Throwable) {
            Log.e(TAG, "clip decode failed for ${clip.uri}", t)
        } finally {
            runCatching { decoder?.stop() }
            runCatching { decoder?.release() }
            runCatching { decoderSurface?.release() }
            runCatching { extractor.release() }
        }
    }

    // ---------------------------------------------------------------- stills

    private fun renderStillClip(
        clip: Clip,
        grader: ColorGrader,
        egl: EglCore,
        eglSurface: android.opengl.EGLSurface,
        encoder: MediaCodec,
        state: MuxState,
        canvasWidth: Int,
        canvasHeight: Int,
        timelineStartUs: Long,
    ) {
        val bitmap: Bitmap = ImageIo.decode(context, Uri.parse(clip.uri), maxEdge = 2160) ?: return
        var texture = 0
        try {
            texture = GlUtils.createTextureFromBitmap(bitmap)
            val frameCount = max(
                1,
                (clip.timelineDurationUs * config.fps / 1_000_000L).toInt(),
            )
            val frameDurationUs = 1_000_000L / config.fps

            for (frame in 0 until frameCount) {
                if (cancelled) return
                val localUs = frame * frameDurationUs
                drawFrame(
                    grader = grader,
                    clip = clip,
                    textureId = texture,
                    texMatrix = ColorGrader.IDENTITY,
                    canvasWidth = canvasWidth,
                    canvasHeight = canvasHeight,
                    sourceWidth = bitmap.width,
                    sourceHeight = bitmap.height,
                    isExternal = false,
                    timelineUs = localUs,
                )
                egl.setPresentationTime(eglSurface, (timelineStartUs + localUs) * 1000L)
                egl.swapBuffers(eglSurface)
                drainEncoder(encoder, state, endOfStream = false)
            }
        } finally {
            GlUtils.deleteTexture(texture)
            bitmap.recycle()
        }
    }

    // ---------------------------------------------------------------- drawing

    /**
     * Clears the canvas and draws one graded frame, honouring the clip's fit
     * mode, transform and fades.
     */
    private fun drawFrame(
        grader: ColorGrader,
        clip: Clip,
        textureId: Int,
        texMatrix: FloatArray,
        canvasWidth: Int,
        canvasHeight: Int,
        sourceWidth: Int,
        sourceHeight: Int,
        isExternal: Boolean,
        timelineUs: Long,
    ) {
        GLES30.glBindFramebuffer(GLES30.GL_FRAMEBUFFER, 0)
        GLES30.glViewport(0, 0, canvasWidth, canvasHeight)
        val bg = project.backgroundColor
        GLES30.glClearColor(
            ((bg shr 16) and 0xFF) / 255f,
            ((bg shr 8) and 0xFF) / 255f,
            (bg and 0xFF) / 255f,
            1f,
        )
        GLES30.glClear(GLES30.GL_COLOR_BUFFER_BIT)

        val transform = clip.transform
        val sourceAspect = sourceWidth.toFloat() / max(1, sourceHeight)
        val canvasAspect = canvasWidth.toFloat() / max(1, canvasHeight)

        // FIT letterboxes by shrinking the viewport; FILL keeps the full
        // viewport and crops through the texture matrix instead.
        var viewportW = canvasWidth
        var viewportH = canvasHeight
        val effectiveTexMatrix = FloatArray(16)
        System.arraycopy(texMatrix, 0, effectiveTexMatrix, 0, 16)

        when (transform.fit) {
            FitMode.FIT -> {
                if (sourceAspect > canvasAspect) {
                    viewportH = (canvasWidth / sourceAspect).roundToInt().coerceAtLeast(1)
                } else {
                    viewportW = (canvasHeight * sourceAspect).roundToInt().coerceAtLeast(1)
                }
            }
            FitMode.FILL -> {
                val scaleX: Float
                val scaleY: Float
                if (sourceAspect > canvasAspect) {
                    scaleX = canvasAspect / sourceAspect
                    scaleY = 1f
                } else {
                    scaleX = 1f
                    scaleY = sourceAspect / canvasAspect
                }
                cropMatrix(effectiveTexMatrix, scaleX, scaleY)
            }
            FitMode.STRETCH -> Unit
        }

        val scale = transform.scale.coerceIn(0.1f, 8f)
        viewportW = (viewportW * scale).roundToInt().coerceAtLeast(1)
        viewportH = (viewportH * scale).roundToInt().coerceAtLeast(1)

        val x = ((canvasWidth - viewportW) / 2f + transform.offsetX * canvasWidth).roundToInt()
        val y = ((canvasHeight - viewportH) / 2f - transform.offsetY * canvasHeight).roundToInt()

        if (transform.flipHorizontal) flipMatrix(effectiveTexMatrix, horizontal = true)
        if (transform.flipVertical) flipMatrix(effectiveTexMatrix, horizontal = false)

        grader.render(
            sourceTexture = textureId,
            isExternal = isExternal,
            texMatrix = effectiveTexMatrix,
            sourceWidth = sourceWidth,
            sourceHeight = sourceHeight,
            targetWidth = viewportW,
            targetHeight = viewportH,
            adjustments = clip.adjustments,
            targetX = x,
            targetY = y,
            opacity = fadeOpacity(clip, timelineUs),
            seed = (timelineUs % 1000L).toFloat(),
        )
    }

    /** 1.0 in the body of the clip, ramping at either end when fades are set. */
    private fun fadeOpacity(clip: Clip, localUs: Long): Float {
        var opacity = 1f
        if (clip.fadeInUs > 0 && localUs < clip.fadeInUs) {
            opacity *= (localUs.toFloat() / clip.fadeInUs).coerceIn(0f, 1f)
        }
        val duration = clip.timelineDurationUs
        if (clip.fadeOutUs > 0 && localUs > duration - clip.fadeOutUs) {
            val remaining = (duration - localUs).toFloat()
            opacity *= (remaining / clip.fadeOutUs).coerceIn(0f, 1f)
        }
        return opacity.coerceIn(0f, 1f)
    }

    private fun cropMatrix(matrix: FloatArray, scaleX: Float, scaleY: Float) {
        val crop = FloatArray(16)
        Matrix.setIdentityM(crop, 0)
        Matrix.translateM(crop, 0, (1f - scaleX) / 2f, (1f - scaleY) / 2f, 0f)
        Matrix.scaleM(crop, 0, scaleX, scaleY, 1f)
        val result = FloatArray(16)
        Matrix.multiplyMM(result, 0, matrix, 0, crop, 0)
        System.arraycopy(result, 0, matrix, 0, 16)
    }

    private fun flipMatrix(matrix: FloatArray, horizontal: Boolean) {
        val flip = FloatArray(16)
        Matrix.setIdentityM(flip, 0)
        if (horizontal) {
            Matrix.translateM(flip, 0, 1f, 0f, 0f)
            Matrix.scaleM(flip, 0, -1f, 1f, 1f)
        } else {
            Matrix.translateM(flip, 0, 0f, 1f, 0f)
            Matrix.scaleM(flip, 0, 1f, -1f, 1f)
        }
        val result = FloatArray(16)
        Matrix.multiplyMM(result, 0, matrix, 0, flip, 0)
        System.arraycopy(result, 0, matrix, 0, 16)
    }

    // ---------------------------------------------------------------- muxing

    private fun drainEncoder(encoder: MediaCodec, state: MuxState, endOfStream: Boolean) {
        val bufferInfo = MediaCodec.BufferInfo()
        // Bounded so a codec that never reports EOS fails the export instead of
        // hanging the thread forever. 10ms per attempt gives ~5s of grace.
        var attemptsLeft = if (endOfStream) 500 else Int.MAX_VALUE
        while (true) {
            val index = encoder.dequeueOutputBuffer(bufferInfo, TIMEOUT_US)
            when {
                index == MediaCodec.INFO_TRY_AGAIN_LATER -> {
                    if (!endOfStream) return
                    if (--attemptsLeft <= 0) {
                        Log.w(TAG, "encoder did not report end of stream; finishing anyway")
                        return
                    }
                }
                index == MediaCodec.INFO_OUTPUT_FORMAT_CHANGED -> {
                    state.setVideoFormat(encoder.outputFormat)
                }
                index >= 0 -> {
                    val buffer: ByteBuffer = encoder.getOutputBuffer(index) ?: continue
                    val isConfig =
                        (bufferInfo.flags and MediaCodec.BUFFER_FLAG_CODEC_CONFIG) != 0
                    if (!isConfig && bufferInfo.size > 0) {
                        buffer.position(bufferInfo.offset)
                        buffer.limit(bufferInfo.offset + bufferInfo.size)
                        state.writeVideo(buffer, bufferInfo)
                    }
                    encoder.releaseOutputBuffer(index, false)
                    if ((bufferInfo.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM) != 0) return
                }
            }
        }
    }

    private fun selectTrack(extractor: MediaExtractor, prefix: String): Int {
        for (i in 0 until extractor.trackCount) {
            val mime = extractor.getTrackFormat(i).getString(MediaFormat.KEY_MIME).orEmpty()
            if (mime.startsWith(prefix)) return i
        }
        return -1
    }

    /**
     * Owns the muxer's lifecycle. MediaMuxer refuses writes before start() and
     * start() needs the encoder's real output format, which only arrives with
     * the first INFO_OUTPUT_FORMAT_CHANGED.
     */
    private class MuxState(private val muxer: MediaMuxer) {
        private var videoTrack = -1
        private var started = false
        private var lastVideoUs = -1L

        fun setVideoFormat(format: MediaFormat) {
            if (started) return
            videoTrack = muxer.addTrack(format)
            muxer.start()
            started = true
        }

        fun writeVideo(buffer: ByteBuffer, info: MediaCodec.BufferInfo) {
            if (!started || videoTrack < 0) return
            // Muxers reject non-monotonic timestamps outright.
            if (info.presentationTimeUs <= lastVideoUs) {
                info.presentationTimeUs = lastVideoUs + 1
            }
            lastVideoUs = info.presentationTimeUs
            muxer.writeSampleData(videoTrack, buffer, info)
        }

        fun finish() {
            if (started) {
                runCatching { muxer.stop() }
                started = false
            }
        }
    }

    private companion object {
        const val MIME_VIDEO = MediaFormat.MIMETYPE_VIDEO_AVC
        /** Video is the long pass; audio decode/mix/encode is comparatively quick. */
        const val VIDEO_SHARE = 0.85f
        const val AUDIO_SHARE = 0.15f
        const val TIMEOUT_US = 10_000L
    }
}
