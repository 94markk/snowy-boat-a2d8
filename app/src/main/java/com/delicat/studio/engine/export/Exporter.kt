package com.delicat.studio.engine.export

import android.content.ContentValues
import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.media.MediaCodec
import android.media.MediaCodecInfo
import android.media.MediaFormat
import android.media.MediaMuxer
import android.media.MediaScannerConnection
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import android.view.Surface
import com.delicat.studio.engine.gl.FrameRenderer
import com.delicat.studio.engine.gl.GlUtil
import com.delicat.studio.engine.gl.Placement
import com.delicat.studio.engine.gl.Transitions
import com.delicat.studio.model.Clip
import com.delicat.studio.model.MediaKind
import com.delicat.studio.model.Project
import com.delicat.studio.model.TransitionType
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.File
import java.nio.ByteBuffer
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlin.math.roundToInt

data class ExportRequest(
    val project: Project,
    /** Short edge of the output. 1080 is what phones record and what sites want. */
    val shortEdge: Int = 1080,
    val fps: Int = 30,
)

/**
 * Renders the timeline to an MP4.
 *
 * The same [FrameRenderer] the preview uses draws each frame, into the
 * encoder's input surface rather than onto the screen. That is what makes the
 * exported colour match the graded colour: not a second implementation kept
 * in agreement, but the identical shader with the identical lookup table,
 * pointed at a different destination.
 */
class Exporter(private val context: Context) {

    @Volatile
    private var cancelled = false

    fun cancel() {
        cancelled = true
    }

    suspend fun run(
        request: ExportRequest,
        onProgress: (Float, String) -> Unit,
    ): ExportState = withContext(Dispatchers.Default) {
        cancelled = false
        try {
            render(request, onProgress)
        } catch (e: Throwable) {
            if (cancelled) ExportState.Idle
            else ExportState.Failed(e.message ?: e.javaClass.simpleName)
        }
    }

    private fun render(request: ExportRequest, onProgress: (Float, String) -> Unit): ExportState {
        val project = request.project
        if (project.isEmpty) return ExportState.Failed("There is nothing on the timeline")

        val (width, height) = request.shortEdge.let { project.aspect.sizeFor(it) }
        val frameDurationUs = 1_000_000L / request.fps
        val totalFrames = (project.durationUs / frameDurationUs).toInt().coerceAtLeast(1)

        // Sound is encoded first because the muxer will not start until every
        // track it is going to carry has been added, and the audio format is
        // not known until its encoder has produced one.
        onProgress(0f, "Mixing sound")
        val audio = encodeAudio(project)
        if (cancelled) return ExportState.Idle

        val encoder = MediaCodec.createEncoderByType(VIDEO_MIME)
        val format = MediaFormat.createVideoFormat(VIDEO_MIME, width, height).apply {
            setInteger(
                MediaFormat.KEY_COLOR_FORMAT,
                MediaCodecInfo.CodecCapabilities.COLOR_FormatSurface,
            )
            setInteger(MediaFormat.KEY_BIT_RATE, bitrateFor(width, height, request.fps))
            setInteger(MediaFormat.KEY_FRAME_RATE, request.fps)
            // One second between keyframes: scrubbing the result stays
            // responsive, and the size cost at this bitrate is slight.
            setInteger(MediaFormat.KEY_I_FRAME_INTERVAL, 1)
        }
        encoder.configure(format, null, null, MediaCodec.CONFIGURE_FLAG_ENCODE)
        val inputSurface: Surface = encoder.createInputSurface()
        encoder.start()

        val egl = EglCore()
        val eglSurface = egl.createWindowSurface(inputSurface)
        egl.makeCurrent(eglSurface)

        val renderer = FrameRenderer()
        renderer.setup()
        if (!renderer.isReady) {
            egl.releaseSurface(eglSurface)
            egl.release()
            encoder.release()
            return ExportState.Failed(renderer.failure ?: "The graphics pipeline could not start")
        }

        val output = createOutput() ?: run {
            renderer.release()
            egl.releaseSurface(eglSurface)
            egl.release()
            encoder.release()
            return ExportState.Failed("Nowhere to save the file")
        }

        val muxer = MediaMuxer(output.path, MediaMuxer.OutputFormat.MUXER_OUTPUT_MPEG_4)
        val decoders = HashMap<String, ClipDecoder>()
        val stills = HashMap<String, StillTexture>()
        val bufferInfo = MediaCodec.BufferInfo()
        val sourceMatrix = FloatArray(16)

        var videoTrack = -1
        var audioTrack = -1
        var muxing = false
        var audioCursor = 0

        try {
            for (frame in 0 until totalFrames) {
                if (cancelled) throw InterruptedException("cancelled")

                val timeUs = frame * frameDurationUs
                val composition = project.compositionAt(timeUs)
                val index = composition.primaryIndex.takeIf { it >= 0 } ?: project.clips.lastIndex

                renderer.beginFrame(width, height, project.backgroundColor)
                renderer.tick()

                val styles = Transitions.styles(
                    if (composition.isTransitioning) composition.transition.type
                    else TransitionType.NONE,
                    composition.progress,
                )

                if (composition.isTransitioning) {
                    project.clips.getOrNull(composition.fromIndex)?.let { clip ->
                        drawClip(
                            renderer, clip,
                            project.sourceTimeFor(composition.fromIndex, timeUs),
                            width, height, decoders, stills, sourceMatrix, styles.outgoing,
                        )
                    }
                }
                project.clips.getOrNull(index)?.let { clip ->
                    drawClip(
                        renderer, clip,
                        project.sourceTimeFor(index, timeUs),
                        width, height, decoders, stills, sourceMatrix, styles.incoming,
                    )
                }

                egl.setPresentationTime(eglSurface, timeUs * 1000L)
                egl.swapBuffers(eglSurface)

                // Drained every frame rather than in a batch at the end: the
                // encoder's output buffers are finite, and letting them fill
                // stalls the surface the next frame has to be drawn into.
                val started = drainVideo(
                    encoder, muxer, bufferInfo, videoTrack, muxing,
                ) { track, nowMuxing ->
                    videoTrack = track
                    if (nowMuxing && !muxing) {
                        if (audio != null) audioTrack = muxer.addTrack(audio.format)
                        muxer.start()
                        muxing = true
                    }
                }
                if (started && muxing && audio != null) {
                    audioCursor = writeAudioUpTo(muxer, audioTrack, audio, audioCursor, timeUs)
                }

                if (frame % 4 == 0) {
                    onProgress(frame.toFloat() / totalFrames, "Rendering")
                }

                // Only the clip being drawn and the one it is fading from can
                // still be needed; anything earlier is holding a decoder open
                // for nothing.
                releaseStaleDecoders(project, decoders, index, composition.fromIndex)
            }

            encoder.signalEndOfInputStream()
            drainVideo(encoder, muxer, bufferInfo, videoTrack, muxing, endOfStream = true) { track, nowMuxing ->
                videoTrack = track
                if (nowMuxing && !muxing) {
                    if (audio != null) audioTrack = muxer.addTrack(audio.format)
                    muxer.start()
                    muxing = true
                }
            }

            if (muxing && audio != null) {
                writeAudioUpTo(muxer, audioTrack, audio, audioCursor, Long.MAX_VALUE)
            }
        } finally {
            decoders.values.forEach { it.release() }
            stills.values.forEach { GlUtil.deleteTexture(it.texture) }
            renderer.release()
            egl.releaseSurface(eglSurface)
            egl.release()
            runCatching { encoder.stop() }
            runCatching { encoder.release() }
            runCatching { if (muxing) muxer.stop() }
            runCatching { muxer.release() }
        }

        if (cancelled) {
            runCatching { File(output.path).delete() }
            return ExportState.Idle
        }

        onProgress(1f, "Saving")
        val uri = publish(output)
        return ExportState.Done(uri.toString(), output.displayName)
    }

    // ---- drawing ----------------------------------------------------------

    private class StillTexture(val texture: Int, val width: Int, val height: Int)

    private fun drawClip(
        renderer: FrameRenderer,
        clip: Clip,
        sourceTimeUs: Long,
        width: Int,
        height: Int,
        decoders: MutableMap<String, ClipDecoder>,
        stills: MutableMap<String, StillTexture>,
        sourceMatrix: FloatArray,
        style: com.delicat.studio.engine.gl.LayerStyle,
    ) {
        if (!style.visible) return

        if (clip.kind == MediaKind.IMAGE) {
            val still = stills.getOrPut(clip.id) { uploadStill(clip) ?: return }
            renderer.drawLayer(
                texture = still.texture,
                isExternal = false,
                sourceMatrix = null,
                sourceWidth = still.width,
                sourceHeight = still.height,
                adjustments = clip.adjustments,
                placement = placement(clip, still.width, still.height, width, height, style, true),
                paint = style.paint,
            )
            return
        }

        val decoder = decoders.getOrPut(clip.id) {
            val created = runCatching { ClipDecoder(context, Uri.parse(clip.uri)) }.getOrNull()
                ?: return
            created.seekTo(clip.trimStartUs)
            created
        }
        decoder.renderUpTo(sourceTimeUs)
        decoder.getTransformMatrix(sourceMatrix)

        renderer.drawLayer(
            texture = decoder.textureId,
            isExternal = true,
            sourceMatrix = sourceMatrix,
            sourceWidth = decoder.width,
            sourceHeight = decoder.height,
            adjustments = clip.adjustments,
            placement = placement(
                clip, decoder.width, decoder.height, width, height, style, false,
                extraTurns = decoder.rotationDegrees / 90,
            ),
            paint = style.paint,
        )
    }

    private fun placement(
        clip: Clip,
        sourceWidth: Int,
        sourceHeight: Int,
        canvasWidth: Int,
        canvasHeight: Int,
        style: com.delicat.studio.engine.gl.LayerStyle,
        flip: Boolean,
        extraTurns: Int = 0,
    ) = Placement(
        canvasWidth = canvasWidth,
        canvasHeight = canvasHeight,
        sourceWidth = sourceWidth,
        sourceHeight = sourceHeight,
        fit = clip.fit,
        quarterTurns = clip.rotationTurns + extraTurns +
            if (clip.kind == MediaKind.IMAGE) clip.sourceRotationDegrees / 90 else 0,
        scale = style.motion.scale,
        offsetX = style.motion.offsetX,
        offsetY = style.motion.offsetY,
        flipVertically = flip,
    )

    private fun uploadStill(clip: Clip): StillTexture? {
        val uri = Uri.parse(clip.uri)
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        context.contentResolver.openInputStream(uri)?.use {
            BitmapFactory.decodeStream(it, null, bounds)
        }
        if (bounds.outWidth <= 0) return null

        var sample = 1
        while (maxOf(bounds.outWidth, bounds.outHeight) / (sample * 2) >= STILL_LIMIT) sample *= 2
        val bitmap = context.contentResolver.openInputStream(uri)?.use {
            BitmapFactory.decodeStream(
                it, null,
                BitmapFactory.Options().apply {
                    inSampleSize = sample
                    inPreferredConfig = Bitmap.Config.ARGB_8888
                },
            )
        } ?: return null

        val texture = GlUtil.createTexture(GlUtil.FLAT_TEXTURE)
        GlUtil.upload(texture, bitmap)
        val result = StillTexture(texture, bitmap.width, bitmap.height)
        bitmap.recycle()
        return result
    }

    private fun releaseStaleDecoders(
        project: Project,
        decoders: MutableMap<String, ClipDecoder>,
        index: Int,
        fromIndex: Int,
    ) {
        if (decoders.size <= 2) return
        val keep = setOfNotNull(
            project.clips.getOrNull(index)?.id,
            project.clips.getOrNull(fromIndex)?.id,
        )
        val stale = decoders.keys.filterNot { it in keep }
        stale.forEach { decoders.remove(it)?.release() }
    }

    // ---- muxing -----------------------------------------------------------

    private inline fun drainVideo(
        encoder: MediaCodec,
        muxer: MediaMuxer,
        info: MediaCodec.BufferInfo,
        currentTrack: Int,
        alreadyMuxing: Boolean,
        endOfStream: Boolean = false,
        onTrack: (Int, Boolean) -> Unit,
    ): Boolean {
        var muxing = alreadyMuxing
        var track = currentTrack
        while (true) {
            val index = encoder.dequeueOutputBuffer(info, if (endOfStream) 10_000L else 0L)
            if (index == MediaCodec.INFO_TRY_AGAIN_LATER) {
                if (!endOfStream) break
                continue
            }
            if (index == MediaCodec.INFO_OUTPUT_FORMAT_CHANGED) {
                track = muxer.addTrack(encoder.outputFormat)
                muxing = true
                onTrack(track, true)
                continue
            }
            if (index < 0) continue

            val buffer = encoder.getOutputBuffer(index)
            // Codec configuration is handed to the muxer through the track
            // format, not written as a sample.
            if (info.flags and MediaCodec.BUFFER_FLAG_CODEC_CONFIG != 0) info.size = 0

            if (info.size > 0 && buffer != null && muxing) {
                buffer.position(info.offset)
                buffer.limit(info.offset + info.size)
                muxer.writeSampleData(track, buffer, info)
            }
            encoder.releaseOutputBuffer(index, false)
            if (info.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM != 0) break
        }
        return muxing
    }

    private class EncodedAudio(
        val format: MediaFormat,
        val chunks: List<Pair<ByteBuffer, MediaCodec.BufferInfo>>,
    )

    /**
     * Encodes the whole soundtrack up front and keeps it in memory.
     *
     * At the bitrate below a ten-minute export is under ten megabytes, and
     * holding it is what lets the muxer learn both track formats before it
     * starts — which it must, because a muxer cannot gain a track once
     * started.
     */
    private fun encodeAudio(project: Project): EncodedAudio? {
        val timeline = AudioTimeline(context)
        if (!timeline.hasAudio(project)) return null

        val codec = runCatching { MediaCodec.createEncoderByType(AUDIO_MIME) }.getOrNull()
            ?: return null
        val format = MediaFormat.createAudioFormat(
            AUDIO_MIME, AudioTimeline.SAMPLE_RATE, AudioTimeline.CHANNELS,
        ).apply {
            setInteger(MediaFormat.KEY_AAC_PROFILE, MediaCodecInfo.CodecProfileLevel.AACObjectLC)
            setInteger(MediaFormat.KEY_BIT_RATE, AUDIO_BITRATE)
            setInteger(MediaFormat.KEY_MAX_INPUT_SIZE, 64 * 1024)
        }

        val chunks = mutableListOf<Pair<ByteBuffer, MediaCodec.BufferInfo>>()
        var outputFormat: MediaFormat? = null
        var framesIn = 0L

        try {
            codec.configure(format, null, null, MediaCodec.CONFIGURE_FLAG_ENCODE)
            codec.start()
            val info = MediaCodec.BufferInfo()

            fun drain(endOfStream: Boolean) {
                while (true) {
                    val index = codec.dequeueOutputBuffer(info, if (endOfStream) 10_000L else 0L)
                    if (index == MediaCodec.INFO_TRY_AGAIN_LATER) {
                        if (!endOfStream) return else continue
                    }
                    if (index == MediaCodec.INFO_OUTPUT_FORMAT_CHANGED) {
                        outputFormat = codec.outputFormat
                        continue
                    }
                    if (index < 0) continue

                    val buffer = codec.getOutputBuffer(index)
                    if (info.flags and MediaCodec.BUFFER_FLAG_CODEC_CONFIG != 0) info.size = 0
                    if (info.size > 0 && buffer != null) {
                        buffer.position(info.offset)
                        buffer.limit(info.offset + info.size)
                        val copy = ByteBuffer.allocate(info.size)
                        copy.put(buffer)
                        copy.flip()
                        val copiedInfo = MediaCodec.BufferInfo().apply {
                            set(0, info.size, info.presentationTimeUs, info.flags)
                        }
                        chunks += copy to copiedInfo
                    }
                    codec.releaseOutputBuffer(index, false)
                    if (info.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM != 0) return
                }
            }

            timeline.render(project, { cancelled }) { pcm, count ->
                var offset = 0
                while (offset < count) {
                    val index = codec.dequeueInputBuffer(10_000L)
                    if (index < 0) {
                        drain(false)
                        continue
                    }
                    val buffer = codec.getInputBuffer(index) ?: break
                    buffer.clear()
                    val shorts = buffer.asShortBuffer()
                    val take = minOf(shorts.remaining(), count - offset)
                    shorts.put(pcm, offset, take)
                    val presentationUs =
                        framesIn * 1_000_000L / AudioTimeline.SAMPLE_RATE
                    codec.queueInputBuffer(index, 0, take * 2, presentationUs, 0)
                    framesIn += take / AudioTimeline.CHANNELS
                    offset += take
                    drain(false)
                }
            }

            val index = codec.dequeueInputBuffer(20_000L)
            if (index >= 0) {
                codec.queueInputBuffer(
                    index, 0, 0,
                    framesIn * 1_000_000L / AudioTimeline.SAMPLE_RATE,
                    MediaCodec.BUFFER_FLAG_END_OF_STREAM,
                )
            }
            drain(true)
        } catch (_: Throwable) {
            return null
        } finally {
            runCatching { codec.stop() }
            runCatching { codec.release() }
        }

        val resolved = outputFormat ?: return null
        if (chunks.isEmpty()) return null
        return EncodedAudio(resolved, chunks)
    }

    private fun writeAudioUpTo(
        muxer: MediaMuxer,
        track: Int,
        audio: EncodedAudio,
        from: Int,
        untilUs: Long,
    ): Int {
        if (track < 0) return from
        var cursor = from
        while (cursor < audio.chunks.size) {
            val (buffer, info) = audio.chunks[cursor]
            if (info.presentationTimeUs > untilUs) break
            buffer.position(0)
            buffer.limit(info.size)
            muxer.writeSampleData(track, buffer, info)
            cursor++
        }
        return cursor
    }

    // ---- output -----------------------------------------------------------

    private class Output(val path: String, val displayName: String)

    private fun createOutput(): Output? {
        val stamp = SimpleDateFormat("yyyyMMdd-HHmmss", Locale.US).format(Date())
        val name = "Delicat-$stamp.mp4"

        // Written to the app's own storage first and published afterwards.
        // MediaMuxer wants a path it can seek in, and moving a finished file
        // into the gallery is both faster and impossible to leave half-written
        // if the export is cancelled.
        val staging = File(context.cacheDir, "exports").apply { mkdirs() }
        val file = File(staging, name)
        return Output(file.absolutePath, name)
    }

    private fun publish(output: Output): Uri {
        val file = File(output.path)
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            val values = ContentValues().apply {
                put(MediaStore.Video.Media.DISPLAY_NAME, output.displayName)
                put(MediaStore.Video.Media.MIME_TYPE, "video/mp4")
                put(MediaStore.Video.Media.RELATIVE_PATH, "Movies/Delicat Studio")
                put(MediaStore.Video.Media.IS_PENDING, 1)
            }
            val collection = MediaStore.Video.Media.getContentUri(
                MediaStore.VOLUME_EXTERNAL_PRIMARY,
            )
            val uri = context.contentResolver.insert(collection, values)
                ?: return Uri.fromFile(file)

            context.contentResolver.openOutputStream(uri)?.use { out ->
                file.inputStream().use { it.copyTo(out) }
            }
            context.contentResolver.update(
                uri,
                ContentValues().apply { put(MediaStore.Video.Media.IS_PENDING, 0) },
                null, null,
            )
            file.delete()
            uri
        } else {
            val movies = Environment.getExternalStoragePublicDirectory(
                Environment.DIRECTORY_MOVIES,
            )
            val folder = File(movies, "Delicat Studio").apply { mkdirs() }
            val target = File(folder, output.displayName)
            runCatching { file.copyTo(target, overwrite = true) }
            file.delete()
            MediaScannerConnection.scanFile(
                context, arrayOf(target.absolutePath), arrayOf("video/mp4"), null,
            )
            Uri.fromFile(target)
        }
    }

    private fun bitrateFor(width: Int, height: Int, fps: Int): Int {
        // Roughly 0.12 bits per pixel per frame: enough that grain and a
        // gradient survive, without producing files nothing will upload.
        val estimate = (width.toLong() * height * fps * 0.12).roundToInt()
        return estimate.coerceIn(2_000_000, 32_000_000)
    }

    private companion object {
        const val VIDEO_MIME = "video/avc"
        const val AUDIO_MIME = "audio/mp4a-latm"
        const val AUDIO_BITRATE = 160_000
        const val STILL_LIMIT = 2048
    }
}
