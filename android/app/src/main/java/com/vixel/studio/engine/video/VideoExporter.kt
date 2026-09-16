package com.vixel.studio.engine.video

import android.content.Context
import android.media.MediaCodec
import android.media.MediaCodecInfo
import android.media.MediaExtractor
import android.media.MediaFormat
import android.media.MediaMuxer
import android.net.Uri
import android.opengl.GLES30
import android.opengl.Matrix
import android.util.Log
import com.vixel.studio.core.model.Clip
import com.vixel.studio.core.model.FitMode
import com.vixel.studio.core.model.MediaKind
import com.vixel.studio.core.model.Project
import com.vixel.studio.engine.audio.AacEncoder
import com.vixel.studio.engine.audio.AudioMixer
import com.vixel.studio.engine.audio.MixSource
import com.vixel.studio.engine.audio.PcmDecoder
import com.vixel.studio.engine.gl.ColorGrader
import com.vixel.studio.engine.gl.EglCore
import com.vixel.studio.engine.gl.GlFramebuffer
import com.vixel.studio.engine.gl.TransitionRenderer
import com.vixel.studio.engine.overlay.OverlayCompositor
import java.io.File
import java.nio.ByteBuffer
import kotlin.math.max
import kotlin.math.roundToInt

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
 * The compositor is timeline-driven: for each output frame it asks whichever
 * clips are visible at that instant for their frame, grades each one, and
 * blends them. Pulling frames rather than pushing them is what makes a
 * transition possible at all, since two clips have to be visible at once, and
 * it also pins the output to exactly [ExportConfig.fps] instead of inheriting
 * whatever cadence the sources happened to have.
 *
 * Grading uses the same [ColorGrader] as the preview, so the file matches the
 * screen by construction rather than by two implementations agreeing.
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

        val workDir = File(
            outputFile.parentFile ?: context.cacheDir,
            "work-${System.currentTimeMillis()}",
        )
        workDir.mkdirs()
        val tempVideo = File(workDir, "video.mp4")

        try {
            renderVideo(tempVideo) { p -> onProgress(p * VIDEO_SHARE) }?.let { return it }

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

            if (!joined) tempVideo.copyTo(outputFile, overwrite = true)

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

    // ----------------------------------------------------------------- video

    private fun renderVideo(outputFile: File, onProgress: (Float) -> Unit): ExportResult.Failure? {
        val (width, height) = project.aspect.sizeFor(config.shortEdge)
        val frameDurationUs = 1_000_000L / config.fps
        val totalFrames = max(1L, project.durationUs / frameDurationUs)

        var encoder: MediaCodec? = null
        var muxer: MediaMuxer? = null
        var egl: EglCore? = null
        var eglSurface: android.opengl.EGLSurface? = null

        val grader = ColorGrader()
        val transitions = TransitionRenderer()
        val overlays = OverlayCompositor()
        val primaryTarget = GlFramebuffer()
        val outgoingTarget = GlFramebuffer()
        val sources = HashMap<Int, ClipSource>()

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
            transitions.init()
            overlays.init()
            primaryTarget.ensure(width, height)
            outgoingTarget.ensure(width, height)

            encoder.start()
            muxer = MediaMuxer(outputFile.absolutePath, MediaMuxer.OutputFormat.MUXER_OUTPUT_MPEG_4)
            val state = MuxState(muxer)

            for (frameIndex in 0 until totalFrames) {
                if (cancelled) return ExportResult.Failure("Export cancelled")

                val timeUs = frameIndex * frameDurationUs
                val composition = project.compositionAt(timeUs)
                if (composition.primaryIndex < 0) break

                // Drop decoders for clips that are no longer on screen. Holding
                // them open would keep a codec instance per clip, and devices
                // cap how many can exist at once.
                val needed = buildSet {
                    add(composition.primaryIndex)
                    if (composition.fromIndex >= 0) add(composition.fromIndex)
                }
                sources.keys.toList()
                    .filterNot { it in needed }
                    .forEach { sources.remove(it)?.release() }

                renderClip(composition.primaryIndex, timeUs, primaryTarget, sources, grader, width, height)

                GLES30.glBindFramebuffer(GLES30.GL_FRAMEBUFFER, 0)
                if (composition.isTransitioning) {
                    renderClip(
                        composition.fromIndex, timeUs, outgoingTarget, sources, grader, width, height,
                    )
                    GLES30.glBindFramebuffer(GLES30.GL_FRAMEBUFFER, 0)
                    transitions.render(
                        fromTexture = outgoingTarget.textureId,
                        toTexture = primaryTarget.textureId,
                        type = composition.transition.type,
                        progress = composition.progress,
                        width = width,
                        height = height,
                    )
                } else {
                    transitions.blit(primaryTarget.textureId, width, height)
                }

                overlays.draw(
                    overlays = project.overlays,
                    timeUs = timeUs,
                    canvasX = 0,
                    canvasY = 0,
                    canvasWidth = width,
                    canvasHeight = height,
                )

                egl.setPresentationTime(eglSurface, timeUs * 1000L)
                egl.swapBuffers(eglSurface)
                drainEncoder(encoder, state, endOfStream = false)

                if (frameIndex % 8 == 0L) {
                    onProgress((frameIndex.toFloat() / totalFrames).coerceIn(0f, 0.99f))
                }
            }

            encoder.signalEndOfInputStream()
            drainEncoder(encoder, state, endOfStream = true)
            state.finish()
            onProgress(1f)
            return null
        } catch (t: Throwable) {
            Log.e(TAG, "video pass failed", t)
            runCatching { outputFile.delete() }
            return ExportResult.Failure(t.message ?: "Export failed", t)
        } finally {
            sources.values.forEach { runCatching { it.release() } }
            runCatching { primaryTarget.release() }
            runCatching { outgoingTarget.release() }
            runCatching { overlays.release() }
            runCatching { transitions.release() }
            runCatching { grader.release() }
            runCatching { encoder?.stop() }
            runCatching { encoder?.release() }
            runCatching { muxer?.release() }
            if (egl != null && eglSurface != null) runCatching { egl.releaseSurface(eglSurface) }
            runCatching { egl?.release() }
        }
    }

    /** Pulls the frame for [index] at [timeUs] and grades it into [target]. */
    private fun renderClip(
        index: Int,
        timeUs: Long,
        target: GlFramebuffer,
        sources: MutableMap<Int, ClipSource>,
        grader: ColorGrader,
        canvasWidth: Int,
        canvasHeight: Int,
    ) {
        val clip = project.clips.getOrNull(index) ?: return
        val source = sources.getOrPut(index) { createSource(clip) }

        source.advanceTo(project.sourceTimeFor(index, timeUs))

        val localUs = timeUs - project.startOf(index)
        target.use {
            val bg = project.backgroundColor
            GLES30.glClearColor(
                ((bg shr 16) and 0xFF) / 255f,
                ((bg shr 8) and 0xFF) / 255f,
                (bg and 0xFF) / 255f,
                1f,
            )
            GLES30.glClear(GLES30.GL_COLOR_BUFFER_BIT)

            val sourceWidth = source.sourceWidth.takeIf { it > 0 } ?: canvasWidth
            val sourceHeight = source.sourceHeight.takeIf { it > 0 } ?: canvasHeight
            if (source.textureId == 0) return@use

            val placement = placementFor(
                clip, sourceWidth, sourceHeight, canvasWidth, canvasHeight, source.transform(),
            )

            grader.render(
                sourceTexture = source.textureId,
                isExternal = source.isExternal,
                texMatrix = placement.texMatrix,
                sourceWidth = sourceWidth,
                sourceHeight = sourceHeight,
                targetWidth = placement.width,
                targetHeight = placement.height,
                adjustments = clip.adjustments,
                targetX = placement.x,
                targetY = placement.y,
                opacity = fadeOpacity(clip, localUs),
                seed = (localUs % 1000L).toFloat(),
            )
        }
    }

    private fun createSource(clip: Clip): ClipSource =
        if (clip.kind == MediaKind.IMAGE) {
            ImageClipSource(context, clip)
        } else {
            VideoClipSource(context, clip).also { it.prepare() }
        }

    private class Placement(
        val x: Int,
        val y: Int,
        val width: Int,
        val height: Int,
        val texMatrix: FloatArray,
    )

    /**
     * Works out where the clip sits inside the canvas.
     *
     * FIT letterboxes by shrinking the viewport; FILL keeps the full viewport
     * and crops through the texture matrix instead.
     */
    private fun placementFor(
        clip: Clip,
        sourceWidth: Int,
        sourceHeight: Int,
        canvasWidth: Int,
        canvasHeight: Int,
        baseMatrix: FloatArray,
    ): Placement {
        val transform = clip.transform
        val sourceAspect = sourceWidth.toFloat() / max(1, sourceHeight)
        val canvasAspect = canvasWidth.toFloat() / max(1, canvasHeight)

        var width = canvasWidth
        var height = canvasHeight
        val texMatrix = FloatArray(16)
        System.arraycopy(baseMatrix, 0, texMatrix, 0, 16)

        when (transform.fit) {
            FitMode.FIT -> {
                if (sourceAspect > canvasAspect) {
                    height = (canvasWidth / sourceAspect).roundToInt().coerceAtLeast(1)
                } else {
                    width = (canvasHeight * sourceAspect).roundToInt().coerceAtLeast(1)
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
                applyCrop(texMatrix, scaleX, scaleY)
            }
            FitMode.STRETCH -> Unit
        }

        val scale = transform.scale.coerceIn(0.1f, 8f)
        width = (width * scale).roundToInt().coerceAtLeast(1)
        height = (height * scale).roundToInt().coerceAtLeast(1)

        if (transform.flipHorizontal) applyFlip(texMatrix, horizontal = true)
        if (transform.flipVertical) applyFlip(texMatrix, horizontal = false)

        val x = ((canvasWidth - width) / 2f + transform.offsetX * canvasWidth).roundToInt()
        val y = ((canvasHeight - height) / 2f - transform.offsetY * canvasHeight).roundToInt()

        return Placement(x, y, width, height, texMatrix)
    }

    /** 1.0 in the body of the clip, ramping at either end when fades are set. */
    private fun fadeOpacity(clip: Clip, localUs: Long): Float {
        var opacity = 1f
        if (clip.fadeInUs > 0 && localUs < clip.fadeInUs) {
            opacity *= (localUs.toFloat() / clip.fadeInUs).coerceIn(0f, 1f)
        }
        val duration = clip.timelineDurationUs
        if (clip.fadeOutUs > 0 && localUs > duration - clip.fadeOutUs) {
            opacity *= ((duration - localUs).toFloat() / clip.fadeOutUs).coerceIn(0f, 1f)
        }
        return opacity.coerceIn(0f, 1f)
    }

    private fun applyCrop(matrix: FloatArray, scaleX: Float, scaleY: Float) {
        val crop = FloatArray(16)
        Matrix.setIdentityM(crop, 0)
        Matrix.translateM(crop, 0, (1f - scaleX) / 2f, (1f - scaleY) / 2f, 0f)
        Matrix.scaleM(crop, 0, scaleX, scaleY, 1f)
        val result = FloatArray(16)
        Matrix.multiplyMM(result, 0, matrix, 0, crop, 0)
        System.arraycopy(result, 0, matrix, 0, 16)
    }

    private fun applyFlip(matrix: FloatArray, horizontal: Boolean) {
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

    // ----------------------------------------------------------------- audio

    /**
     * Decodes every audible source to PCM, mixes them onto one timeline and
     * encodes the result to AAC.
     *
     * @return the encoded m4a, or null when the project is silent.
     */
    private fun buildAudioTrack(workDir: File, onProgress: (Float) -> Unit): File? {
        val sources = mutableListOf<MixSource>()
        var index = 0

        // Audio riding along with the video clips. Placement uses startOf so a
        // transition's overlap pulls the incoming audio earlier too.
        project.clips.forEachIndexed { clipIndex, clip ->
            if (clip.kind != MediaKind.VIDEO || clip.muted || clip.volume <= 0f) return@forEachIndexed

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
                return@forEachIndexed
            }
            sources += MixSource(
                pcm = pcm,
                startFrame = PcmDecoder.usToFrames(project.startOf(clipIndex)),
                volume = clip.volume,
                fadeInFrames = PcmDecoder.usToFrames(clip.fadeInUs),
                fadeOutFrames = PcmDecoder.usToFrames(clip.fadeOutUs),
            )
        }
        onProgress(0.5f)

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
                    val isConfig = (bufferInfo.flags and MediaCodec.BUFFER_FLAG_CODEC_CONFIG) != 0
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

    @Suppress("unused")
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
        const val TIMEOUT_US = 10_000L

        /** Video is the long pass; audio decode/mix/encode is comparatively quick. */
        const val VIDEO_SHARE = 0.85f
        const val AUDIO_SHARE = 0.15f
    }
}
