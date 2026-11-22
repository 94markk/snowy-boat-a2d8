package ht.delicat.delicat_studio

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.media.MediaMetadataRetriever
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.Looper
import androidx.annotation.OptIn
import androidx.media3.common.C
import androidx.media3.common.Effect
import androidx.media3.common.MediaItem
import androidx.media3.common.MimeTypes
import androidx.media3.common.audio.AudioProcessor
import androidx.media3.common.audio.ChannelMixingAudioProcessor
import androidx.media3.common.audio.ChannelMixingMatrix
import androidx.media3.common.audio.SpeedProvider
import androidx.media3.common.util.UnstableApi
import androidx.media3.effect.BitmapOverlay
import androidx.media3.effect.DefaultVideoFrameProcessor
import androidx.media3.effect.MatrixTransformation
import androidx.media3.effect.OverlayEffect
import androidx.media3.effect.Presentation
import androidx.media3.effect.RgbMatrix
import androidx.media3.effect.ScaleAndRotateTransformation
import androidx.media3.effect.SingleColorLut
import androidx.media3.transformer.Composition
import androidx.media3.transformer.DefaultEncoderFactory
import androidx.media3.transformer.EditedMediaItem
import androidx.media3.transformer.EditedMediaItemSequence
import androidx.media3.transformer.Effects
import androidx.media3.transformer.ExportException
import androidx.media3.transformer.ExportResult
import androidx.media3.transformer.ProgressHolder
import androidx.media3.transformer.Transformer
import androidx.media3.transformer.VideoEncoderSettings
import io.flutter.plugin.common.BinaryMessenger
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel
import org.json.JSONArray
import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.io.File
import java.io.FileOutputStream
import java.util.concurrent.Executors
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

/**
 * Video features for Delicat Studio: probing, filmstrip thumbnails, frame grabs and
 * MP4 export with Media3 Transformer (hardware H.264 + AAC, everything on the device).
 */
@OptIn(UnstableApi::class)
class VideoEngine(private val context: Context) : MethodChannel.MethodCallHandler {
    private val io = Executors.newSingleThreadExecutor()
    private val main = Handler(Looper.getMainLooper())
    private var channel: MethodChannel? = null
    private var transformer: Transformer? = null
    private var pending: MethodChannel.Result? = null
    private val progress = ProgressHolder()

    fun attach(messenger: BinaryMessenger) {
        channel = MethodChannel(messenger, "ht.delicat.studio/video").also { it.setMethodCallHandler(this) }
    }

    fun dispose() {
        transformer?.cancel()
        transformer = null
        channel?.setMethodCallHandler(null)
        io.shutdown()
    }

    override fun onMethodCall(call: MethodCall, result: MethodChannel.Result) {
        when (call.method) {
            "probe" -> background(result) { probe(call.argument<String>("path")!!) }
            "thumbnails" -> background(result) {
                thumbnails(
                    call.argument<String>("path")!!,
                    call.argument<Int>("count") ?: 8,
                    call.argument<Int>("height") ?: 96,
                    (call.argument<Number>("startMs") ?: 0).toLong(),
                    (call.argument<Number>("endMs") ?: 0).toLong(),
                )
            }
            "frame" -> background(result) {
                frame(
                    call.argument<String>("path")!!,
                    (call.argument<Number>("timeMs") ?: 0).toLong(),
                    call.argument<Int>("maxSide") ?: 2048,
                    call.argument<String>("output")!!,
                )
            }
            "export" -> export(call.argument<String>("spec")!!, result)
            "progress" -> result.success(currentProgress())
            "cancel" -> {
                transformer?.cancel()
                transformer = null
                finish { it.error("cancelled", "Export annulé", null) }
                result.success(null)
            }
            else -> result.notImplemented()
        }
    }

    private fun background(result: MethodChannel.Result, block: () -> Any?) {
        io.execute {
            try {
                val value = block()
                main.post { result.success(value) }
            } catch (e: Throwable) {
                // Throwable, not Exception: an OutOfMemoryError must still answer Dart.
                main.post { result.error("video", e.message ?: e.toString(), null) }
            }
        }
    }

    // ---------------------------------------------------------------- probing

    private fun probe(path: String): Map<String, Any> {
        val r = MediaMetadataRetriever()
        try {
            r.setDataSource(path)
            val duration = r.extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)?.toLongOrNull() ?: 0L
            var w = r.extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_WIDTH)?.toIntOrNull() ?: 0
            var h = r.extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_HEIGHT)?.toIntOrNull() ?: 0
            val rotation = r.extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_ROTATION)?.toIntOrNull() ?: 0
            if (rotation == 90 || rotation == 270) {
                val t = w; w = h; h = t
            }
            val hasAudio = r.extractMetadata(MediaMetadataRetriever.METADATA_KEY_HAS_AUDIO) == "yes"
            val hasVideo = r.extractMetadata(MediaMetadataRetriever.METADATA_KEY_HAS_VIDEO) == "yes"
            return mapOf("durationMs" to duration, "width" to w, "height" to h, "hasAudio" to hasAudio, "hasVideo" to hasVideo)
        } finally {
            r.release()
        }
    }

    private fun grab(r: MediaMetadataRetriever, timeMs: Long, maxW: Int, maxH: Int, exact: Boolean): Bitmap? {
        val us = timeMs * 1000
        val option = if (exact) MediaMetadataRetriever.OPTION_CLOSEST else MediaMetadataRetriever.OPTION_CLOSEST_SYNC
        return if (Build.VERSION.SDK_INT >= 27) {
            r.getScaledFrameAtTime(us, option, maxW, maxH)
        } else {
            val full = r.getFrameAtTime(us, option) ?: return null
            val s = min(maxW.toFloat() / full.width, maxH.toFloat() / full.height).coerceAtMost(1f)
            if (s >= 1f) {
                full
            } else {
                val scaled = Bitmap.createScaledBitmap(full, (full.width * s).roundToInt(), (full.height * s).roundToInt(), true)
                if (scaled !== full) full.recycle()
                scaled
            }
        }
    }

    private fun thumbnails(path: String, count: Int, height: Int, startMs: Long, endMs: Long): List<ByteArray> {
        val r = MediaMetadataRetriever()
        try {
            r.setDataSource(path)
            val duration = r.extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)?.toLongOrNull() ?: 0L
            val end = if (endMs > startMs) endMs else duration
            val out = ArrayList<ByteArray>()
            for (i in 0 until max(1, count)) {
                val t = startMs + ((end - startMs) * (i + 0.5) / max(1, count)).toLong()
                val bmp = grab(r, t, height * 3, height, exact = false) ?: continue
                val bytes = ByteArrayOutputStream()
                bmp.compress(Bitmap.CompressFormat.JPEG, 70, bytes)
                out.add(bytes.toByteArray())
            }
            return out
        } finally {
            r.release()
        }
    }

    private fun frame(path: String, timeMs: Long, maxSide: Int, output: String): String {
        val r = MediaMetadataRetriever()
        try {
            r.setDataSource(path)
            val bmp = grab(r, timeMs, maxSide, maxSide, exact = true) ?: throw IllegalStateException("Image introuvable à cet instant")
            FileOutputStream(File(output)).use { bmp.compress(Bitmap.CompressFormat.JPEG, 92, it) }
            return output
        } finally {
            r.release()
        }
    }

    // ---------------------------------------------------------------- export

    private fun currentProgress(): Int {
        val t = transformer ?: return -1
        return if (t.getProgress(progress) == Transformer.PROGRESS_STATE_AVAILABLE) progress.progress else 0
    }

    private fun finish(block: (MethodChannel.Result) -> Unit) {
        val r = pending ?: return
        pending = null
        block(r)
    }

    private fun export(specJson: String, result: MethodChannel.Result) {
        if (pending != null) {
            result.error("busy", "Un export est déjà en cours", null)
            return
        }
        try {
            val spec = JSONObject(specJson)
            val width = spec.getInt("width")
            val height = spec.getInt("height")
            val fill = spec.optBoolean("fill", false)
            val fps = spec.optInt("fps", 30)
            val output = spec.getString("output")

            val clips = spec.getJSONArray("clips")
            val items = ArrayList<EditedMediaItem>()
            for (i in 0 until clips.length()) items.add(clipItem(clips.getJSONObject(i), width, height, fill, fps))
            val sequences = ArrayList<EditedMediaItemSequence>()
            sequences.add(
                EditedMediaItemSequence.Builder(setOf(C.TRACK_TYPE_AUDIO, C.TRACK_TYPE_VIDEO))
                    .addItems(items)
                    .experimentalSetForceAudioTrack(true)
                    .build(),
            )
            spec.optJSONObject("music")?.let { sequences.add(musicSequence(it)) }
            spec.optJSONArray("voices")?.let { voices ->
                for (i in 0 until voices.length()) sequences.add(voiceSequence(voices.getJSONObject(i)))
            }

            val compositionEffects = ArrayList<Effect>()
            val segments = segments(spec.optJSONArray("timeline"))
            if (segments.any { it.zoom != 0 }) compositionEffects.add(ZoomAnimation(segments))
            if (segments.any { it.fadeInMs > 0 || it.fadeOutMs > 0 }) compositionEffects.add(TimelineFade(segments))
            val overlays = overlaySegments(spec.optJSONArray("overlays"))
            if (overlays.isNotEmpty()) compositionEffects.add(OverlayEffect(listOf(TimedOverlay(width, height, overlays))))

            val composition = Composition.Builder(sequences)
                .setEffects(if (compositionEffects.isEmpty()) Effects.EMPTY else Effects(listOf(), compositionEffects))
                .build()

            File(output).delete()
            val encoder = DefaultEncoderFactory.Builder(context)
                .setRequestedVideoEncoderSettings(VideoEncoderSettings.Builder().setBitrate(spec.optInt("bitrate", 10_000_000)).build())
                .setEnableFallback(true)
                .build()
            val frameProcessor = DefaultVideoFrameProcessor.Factory.Builder()
                // Apply colour effects on display-encoded values so exports match the in-app preview.
                .setSdrWorkingColorSpace(DefaultVideoFrameProcessor.WORKING_COLOR_SPACE_ORIGINAL)
                .build()
            pending = result
            val t = Transformer.Builder(context)
                .setVideoMimeType(MimeTypes.VIDEO_H264)
                .setAudioMimeType(MimeTypes.AUDIO_AAC)
                .setEncoderFactory(encoder)
                .setVideoFrameProcessorFactory(frameProcessor)
                .addListener(object : Transformer.Listener {
                    override fun onCompleted(composition: Composition, exportResult: ExportResult) {
                        transformer = null
                        finish { it.success(output) }
                    }

                    override fun onError(composition: Composition, exportResult: ExportResult, exportException: ExportException) {
                        transformer = null
                        finish { it.error("export", exportException.message ?: "Échec de l'export", exportException.errorCodeName) }
                    }
                })
                .build()
            transformer = t
            t.start(composition, output)
        } catch (e: Exception) {
            transformer = null
            pending = null
            result.error("export", e.message ?: e.toString(), null)
        }
    }

    private fun volumeProcessor(volume: Float): AudioProcessor {
        val p = ChannelMixingAudioProcessor()
        for (channels in 1..2) p.putChannelMixingMatrix(ChannelMixingMatrix.createForConstantGain(channels, channels).scaleBy(volume))
        return p
    }

    private fun clipItem(o: JSONObject, width: Int, height: Int, fill: Boolean, fps: Int): EditedMediaItem {
        val uri = Uri.fromFile(File(o.getString("path")))
        val image = o.optBoolean("image", false)
        val mediaItem = if (image) {
            MediaItem.Builder().setUri(uri).setImageDurationMs(o.getLong("durationMs")).build()
        } else {
            MediaItem.Builder()
                .setUri(uri)
                .setClippingConfiguration(
                    MediaItem.ClippingConfiguration.Builder()
                        .setStartPositionMs(o.getLong("inMs"))
                        .setEndPositionMs(o.getLong("outMs"))
                        .build(),
                )
                .build()
        }
        val video = ArrayList<Effect>()
        val rotation = o.optInt("rotation", 0)
        val flip = o.optBoolean("flipH", false)
        // Same order as the preview: rotate first, then mirror the rotated picture.
        if (rotation != 0) {
            video.add(
                ScaleAndRotateTransformation.Builder()
                    // Media3 rotates counter-clockwise; the app rotates clockwise.
                    .setRotationDegrees(((360 - rotation) % 360).toFloat())
                    .build(),
            )
        }
        if (flip) video.add(ScaleAndRotateTransformation.Builder().setScale(-1f, 1f).build())
        video.add(
            Presentation.createForWidthAndHeight(
                width,
                height,
                if (fill) Presentation.LAYOUT_SCALE_TO_FIT_WITH_CROP else Presentation.LAYOUT_SCALE_TO_FIT,
            ),
        )
        o.optJSONArray("matrix")?.let { video.add(SingleColorLut.createFromCube(lutFrom(it))) }

        val audio = ArrayList<AudioProcessor>()
        val volume = o.optDouble("volume", 1.0).toFloat()
        val mute = o.optBoolean("mute", false)
        if (!mute && volume != 1f) audio.add(volumeProcessor(volume))

        val builder = EditedMediaItem.Builder(mediaItem)
            .setEffects(Effects(audio, video))
            .setRemoveAudio(mute || image)
        if (image) builder.setFrameRate(fps)
        val speed = o.optDouble("speed", 1.0).toFloat()
        if (!image && speed != 1f) {
            builder.setSpeed(object : SpeedProvider {
                override fun getSpeed(timeUs: Long): Float = speed
                override fun getNextSpeedChangeTimeUs(timeUs: Long): Long = C.TIME_UNSET
            })
        }
        return builder.build()
    }

    private fun musicSequence(o: JSONObject): EditedMediaItemSequence {
        val clipping = MediaItem.ClippingConfiguration.Builder().setStartPositionMs(o.optLong("inMs", 0))
        if (o.has("outMs")) clipping.setEndPositionMs(o.getLong("outMs"))
        val item = MediaItem.Builder()
            .setUri(Uri.fromFile(File(o.getString("path"))))
            .setClippingConfiguration(clipping.build())
            .build()
        val edited = EditedMediaItem.Builder(item)
            .setRemoveVideo(true)
            .setEffects(Effects(listOf(volumeProcessor(o.optDouble("volume", 1.0).toFloat())), listOf()))
            .build()
        return EditedMediaItemSequence.Builder(setOf(C.TRACK_TYPE_AUDIO))
            .addItem(edited)
            .setIsLooping(o.optBoolean("loop", true))
            .build()
    }

    /** A voiceover placed at [startMs] on the timeline (a leading gap keeps it in sync). */
    private fun voiceSequence(o: JSONObject): EditedMediaItemSequence {
        val item = MediaItem.Builder().setUri(Uri.fromFile(File(o.getString("path"))))
        // Never let a voice-over run past the end of the montage.
        val maxMs = o.optLong("maxMs", 0)
        if (maxMs > 0) item.setClippingConfiguration(MediaItem.ClippingConfiguration.Builder().setEndPositionMs(maxMs).build())
        val edited = EditedMediaItem.Builder(item.build())
            .setRemoveVideo(true)
            .setEffects(Effects(listOf(volumeProcessor(o.optDouble("volume", 1.0).toFloat())), listOf()))
            .build()
        val builder = EditedMediaItemSequence.Builder(setOf(C.TRACK_TYPE_AUDIO))
        val startUs = o.optLong("startMs", 0) * 1000
        if (startUs > 0) builder.addGap(startUs)
        return builder.addItem(edited).build()
    }

    /** Builds a 33×33×33 LUT from the app's 4×5 colour matrix (offsets in 0..255). */
    private fun lutFrom(m: JSONArray): Array<Array<IntArray>> {
        val k = FloatArray(20) { m.getDouble(it).toFloat() }
        val n = 33
        return Array(n) { ri ->
            Array(n) { gi ->
                IntArray(n) { bi ->
                    val r = ri * 255f / (n - 1)
                    val g = gi * 255f / (n - 1)
                    val b = bi * 255f / (n - 1)
                    fun ch(row: Int): Int =
                        (k[row * 5] * r + k[row * 5 + 1] * g + k[row * 5 + 2] * b + k[row * 5 + 3] * 255f + k[row * 5 + 4])
                            .roundToInt().coerceIn(0, 255)
                    (0xFF shl 24) or (ch(0) shl 16) or (ch(1) shl 8) or ch(2)
                }
            }
        }
    }

    class Segment(val startMs: Long, val endMs: Long, val fadeInMs: Long, val fadeOutMs: Long, val zoom: Int)

    private fun segments(a: JSONArray?): List<Segment> {
        if (a == null) return listOf()
        return (0 until a.length()).map {
            val o = a.getJSONObject(it)
            Segment(
                o.getLong("startMs"),
                o.getLong("endMs"),
                o.optLong("fadeInMs", 0),
                o.optLong("fadeOutMs", 0),
                when (o.optString("zoom")) { "in" -> 1; "out" -> -1; else -> 0 },
            )
        }
    }

    class OverlaySegment(val startMs: Long, val endMs: Long, val path: String)

    private fun overlaySegments(a: JSONArray?): List<OverlaySegment> {
        if (a == null) return listOf()
        return (0 until a.length()).map {
            val o = a.getJSONObject(it)
            OverlaySegment(o.getLong("startMs"), o.getLong("endMs"), o.getString("path"))
        }
    }

    /** Fades to and from black at clip edges (also used for « Fondu » transitions). */
    class TimelineFade(private val segments: List<Segment>) : RgbMatrix {
        override fun getMatrix(presentationTimeUs: Long, useHdr: Boolean): FloatArray {
            val ms = presentationTimeUs / 1000
            var f = 1f
            val s = segments.firstOrNull { ms >= it.startMs && ms < it.endMs } ?: segments.lastOrNull()
            if (s != null) {
                val local = ms - s.startMs
                val left = s.endMs - ms
                if (s.fadeInMs > 0 && local < s.fadeInMs) f = min(f, local.toFloat() / s.fadeInMs)
                if (s.fadeOutMs > 0 && left < s.fadeOutMs) f = min(f, left.toFloat() / s.fadeOutMs)
            }
            f = f.coerceIn(0f, 1f)
            return floatArrayOf(f, 0f, 0f, 0f, 0f, f, 0f, 0f, 0f, 0f, f, 0f, 0f, 0f, 0f, 1f)
        }
    }

    /** Slow zoom in / out across a clip (Ken Burns). */
    class ZoomAnimation(private val segments: List<Segment>) : MatrixTransformation {
        override fun getMatrix(presentationTimeUs: Long): Matrix {
            val ms = presentationTimeUs / 1000
            val s = segments.firstOrNull { ms >= it.startMs && ms < it.endMs }
            val m = Matrix()
            if (s == null || s.zoom == 0) return m
            val p = ((ms - s.startMs).toFloat() / max(1L, s.endMs - s.startMs)).coerceIn(0f, 1f)
            val scale = if (s.zoom > 0) 1f + 0.18f * p else 1.18f - 0.18f * p
            m.postScale(scale, scale)
            return m
        }
    }

    /** Full-frame text/sticker/logo images, switched by timeline position. */
    class TimedOverlay(private val width: Int, private val height: Int, private val segments: List<OverlaySegment>) : BitmapOverlay() {
        private val empty: Bitmap by lazy { Bitmap.createBitmap(width, height, Bitmap.Config.ARGB_8888) }
        private var currentPath: String? = null
        private var current: Bitmap? = null

        override fun getBitmap(presentationTimeUs: Long): Bitmap {
            val ms = presentationTimeUs / 1000
            val s = segments.firstOrNull { ms >= it.startMs && ms < it.endMs } ?: return empty
            if (s.path != currentPath) {
                // Straight alpha: the overlay shader applies alpha itself, so premultiplied
                // pixels would be faded twice.
                val opts = BitmapFactory.Options().apply {
                    inPreferredConfig = Bitmap.Config.ARGB_8888
                    inPremultiplied = false
                }
                current?.recycle()
                current = BitmapFactory.decodeFile(s.path, opts)
                currentPath = s.path
            }
            return current ?: empty
        }
    }
}
