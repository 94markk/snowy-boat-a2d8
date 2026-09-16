package com.vixel.studio.core.store

import com.vixel.studio.core.model.AdjustSpec
import com.vixel.studio.core.model.Adjustments
import com.vixel.studio.core.model.AspectRatio
import com.vixel.studio.core.model.AudioClip
import com.vixel.studio.core.model.Clip
import com.vixel.studio.core.model.CurvePoint
import com.vixel.studio.core.model.FitMode
import com.vixel.studio.core.model.MediaKind
import com.vixel.studio.core.model.Overlay
import com.vixel.studio.core.model.OverlayAnimation
import com.vixel.studio.core.model.OverlayTransform
import com.vixel.studio.core.model.Project
import com.vixel.studio.core.model.StickerArt
import com.vixel.studio.core.model.StickerOverlay
import com.vixel.studio.core.model.StickerShape
import com.vixel.studio.core.model.TextAlignment
import com.vixel.studio.core.model.TextFont
import com.vixel.studio.core.model.TextOverlay
import com.vixel.studio.core.model.TextStyle
import com.vixel.studio.core.model.Transform
import com.vixel.studio.core.model.Transition
import com.vixel.studio.core.model.TransitionType
import org.json.JSONArray
import org.json.JSONObject

/**
 * Project serialisation, using the framework's own JSON rather than a
 * reflection-based library: the format stays explicit and readable, and it
 * survives R8 without keep rules.
 *
 * Reading is deliberately forgiving. Every field falls back to the model
 * default, so a project written by an older build still opens instead of
 * failing outright on a key that did not exist yet.
 */
object ProjectJson {

    const val VERSION = 1

    // ------------------------------------------------------------ write

    fun toJson(project: Project): JSONObject = JSONObject().apply {
        put("version", VERSION)
        put("id", project.id)
        put("name", project.name)
        put("aspect", project.aspect.id)
        put("backgroundColor", project.backgroundColor)
        put("createdAt", project.createdAt)
        put("clips", JSONArray().apply { project.clips.forEach { put(clipToJson(it)) } })
        put("audio", JSONArray().apply { project.audio.forEach { put(audioToJson(it)) } })
        put("overlays", JSONArray().apply { project.overlays.forEach { put(overlayToJson(it)) } })
    }

    private fun clipToJson(clip: Clip): JSONObject = JSONObject().apply {
        put("id", clip.id)
        put("uri", clip.uri)
        put("kind", clip.kind.name)
        put("sourceDurationUs", clip.sourceDurationUs)
        put("trimStartUs", clip.trimStartUs)
        put("trimEndUs", clip.trimEndUs)
        put("speed", clip.speed.toDouble())
        put("volume", clip.volume.toDouble())
        put("muted", clip.muted)
        put("reversed", clip.reversed)
        put("fadeInUs", clip.fadeInUs)
        put("fadeOutUs", clip.fadeOutUs)
        put("sourceWidth", clip.sourceWidth)
        put("sourceHeight", clip.sourceHeight)
        put("sourceRotationDegrees", clip.sourceRotationDegrees)
        put("adjustments", adjustmentsToJson(clip.adjustments))
        put("transform", transformToJson(clip.transform))
        put(
            "transition",
            JSONObject().apply {
                put("type", clip.transition.type.name)
                put("durationUs", clip.transition.durationUs)
            },
        )
    }

    private fun adjustmentsToJson(a: Adjustments): JSONObject = JSONObject().apply {
        // Scalars come straight from the spec registry, so a new parameter is
        // persisted automatically once it is declared there.
        AdjustSpec.ALL.forEach { spec -> put(spec.id, a.get(spec.id).toDouble()) }
        put("filterId", a.filterId)
        put("filterStrength", a.filterStrength.toDouble())
        put("hslHue", floatsToJson(a.hslHue))
        put("hslSat", floatsToJson(a.hslSat))
        put("hslLum", floatsToJson(a.hslLum))
        put("curveMaster", curveToJson(a.curveMaster))
        put("curveRed", curveToJson(a.curveRed))
        put("curveGreen", curveToJson(a.curveGreen))
        put("curveBlue", curveToJson(a.curveBlue))
    }

    private fun transformToJson(t: Transform): JSONObject = JSONObject().apply {
        put("scale", t.scale.toDouble())
        put("offsetX", t.offsetX.toDouble())
        put("offsetY", t.offsetY.toDouble())
        put("rotationDegrees", t.rotationDegrees.toDouble())
        put("flipHorizontal", t.flipHorizontal)
        put("flipVertical", t.flipVertical)
        put("fit", t.fit.name)
    }

    private fun audioToJson(a: AudioClip): JSONObject = JSONObject().apply {
        put("id", a.id)
        put("uri", a.uri)
        put("title", a.title)
        put("sourceDurationUs", a.sourceDurationUs)
        put("startOnTimelineUs", a.startOnTimelineUs)
        put("trimStartUs", a.trimStartUs)
        put("trimEndUs", a.trimEndUs)
        put("volume", a.volume.toDouble())
        put("fadeInUs", a.fadeInUs)
        put("fadeOutUs", a.fadeOutUs)
        put("loop", a.loop)
    }

    private fun overlayToJson(o: Overlay): JSONObject = JSONObject().apply {
        put("id", o.id)
        put("startUs", o.startUs)
        put("endUs", o.endUs)
        put("animationIn", o.animationIn.name)
        put("animationOut", o.animationOut.name)
        put("animationDurationUs", o.animationDurationUs)
        put(
            "transform",
            JSONObject().apply {
                put("x", o.transform.x.toDouble())
                put("y", o.transform.y.toDouble())
                put("scale", o.transform.scale.toDouble())
                put("rotationDegrees", o.transform.rotationDegrees.toDouble())
                put("opacity", o.transform.opacity.toDouble())
            },
        )
        when (o) {
            is TextOverlay -> {
                put("kind", "text")
                put("text", o.text)
                put("style", textStyleToJson(o.style))
            }
            is StickerOverlay -> {
                put("kind", "sticker")
                put("sizeFraction", o.sizeFraction.toDouble())
                when (val art = o.art) {
                    is StickerArt.Emoji -> {
                        put("artKind", "emoji")
                        put("glyph", art.glyph)
                    }
                    is StickerArt.Shape -> {
                        put("artKind", "shape")
                        put("shape", art.shape.name)
                        put("shapeColor", art.color)
                    }
                }
            }
        }
    }

    private fun textStyleToJson(s: TextStyle): JSONObject = JSONObject().apply {
        put("font", s.font.name)
        put("bold", s.bold)
        put("italic", s.italic)
        put("sizeFraction", s.sizeFraction.toDouble())
        put("color", s.color)
        put("alignment", s.alignment.name)
        put("letterSpacing", s.letterSpacing.toDouble())
        put("lineSpacing", s.lineSpacing.toDouble())
        put("strokeWidth", s.strokeWidth.toDouble())
        put("strokeColor", s.strokeColor)
        put("shadowRadius", s.shadowRadius.toDouble())
        put("shadowDx", s.shadowDx.toDouble())
        put("shadowDy", s.shadowDy.toDouble())
        put("shadowColor", s.shadowColor)
        put("backgroundColor", s.backgroundColor)
        put("backgroundPadding", s.backgroundPadding.toDouble())
        put("backgroundRadius", s.backgroundRadius.toDouble())
    }

    private fun floatsToJson(values: FloatArray): JSONArray =
        JSONArray().apply { values.forEach { put(it.toDouble()) } }

    private fun curveToJson(points: List<CurvePoint>): JSONArray = JSONArray().apply {
        points.forEach { put(JSONObject().apply { put("x", it.x.toDouble()); put("y", it.y.toDouble()) }) }
    }

    // ------------------------------------------------------------- read

    fun fromJson(json: JSONObject): Project {
        val defaults = Project()
        return Project(
            id = json.optString("id", defaults.id),
            name = json.optString("name", defaults.name),
            aspect = AspectRatio.fromId(json.optString("aspect", AspectRatio.DEFAULT.id)),
            clips = json.optJSONArray("clips").mapObjects { clipFromJson(it) },
            audio = json.optJSONArray("audio").mapObjects { audioFromJson(it) },
            overlays = json.optJSONArray("overlays").mapObjects { overlayFromJson(it) },
            backgroundColor = json.optInt("backgroundColor", defaults.backgroundColor),
            createdAt = json.optLong("createdAt", defaults.createdAt),
        )
    }

    private fun clipFromJson(json: JSONObject): Clip? {
        val uri = json.optString("uri").takeIf { it.isNotEmpty() } ?: return null
        val duration = json.optLong("sourceDurationUs", Clip.MIN_CLIP_US)
            .coerceAtLeast(Clip.MIN_CLIP_US)
        return Clip(
            id = json.optString("id", java.util.UUID.randomUUID().toString()),
            uri = uri,
            kind = enumOr(json.optString("kind"), MediaKind.VIDEO),
            sourceDurationUs = duration,
            trimStartUs = json.optLong("trimStartUs", 0L),
            trimEndUs = json.optLong("trimEndUs", duration),
            speed = json.optDouble("speed", 1.0).toFloat(),
            volume = json.optDouble("volume", 1.0).toFloat(),
            muted = json.optBoolean("muted", false),
            reversed = json.optBoolean("reversed", false),
            adjustments = adjustmentsFromJson(json.optJSONObject("adjustments")),
            transform = transformFromJson(json.optJSONObject("transform")),
            fadeInUs = json.optLong("fadeInUs", 0L),
            fadeOutUs = json.optLong("fadeOutUs", 0L),
            transition = json.optJSONObject("transition")?.let {
                Transition(
                    type = enumOr(it.optString("type"), TransitionType.NONE),
                    durationUs = it.optLong("durationUs", 500_000L),
                )
            } ?: Transition.NONE,
            sourceWidth = json.optInt("sourceWidth", 0),
            sourceHeight = json.optInt("sourceHeight", 0),
            sourceRotationDegrees = json.optInt("sourceRotationDegrees", 0),
        )
    }

    private fun adjustmentsFromJson(json: JSONObject?): Adjustments {
        if (json == null) return Adjustments()
        var result = Adjustments(
            filterId = json.optString("filterId", Adjustments().filterId),
            filterStrength = json.optDouble("filterStrength", 1.0).toFloat(),
            hslHue = floatsFromJson(json.optJSONArray("hslHue")),
            hslSat = floatsFromJson(json.optJSONArray("hslSat")),
            hslLum = floatsFromJson(json.optJSONArray("hslLum")),
            curveMaster = curveFromJson(json.optJSONArray("curveMaster")),
            curveRed = curveFromJson(json.optJSONArray("curveRed")),
            curveGreen = curveFromJson(json.optJSONArray("curveGreen")),
            curveBlue = curveFromJson(json.optJSONArray("curveBlue")),
        )
        AdjustSpec.ALL.forEach { spec ->
            result = result.set(spec.id, json.optDouble(spec.id, spec.default.toDouble()).toFloat())
        }
        return result
    }

    private fun transformFromJson(json: JSONObject?): Transform {
        if (json == null) return Transform()
        return Transform(
            scale = json.optDouble("scale", 1.0).toFloat(),
            offsetX = json.optDouble("offsetX", 0.0).toFloat(),
            offsetY = json.optDouble("offsetY", 0.0).toFloat(),
            rotationDegrees = json.optDouble("rotationDegrees", 0.0).toFloat(),
            flipHorizontal = json.optBoolean("flipHorizontal", false),
            flipVertical = json.optBoolean("flipVertical", false),
            fit = enumOr(json.optString("fit"), FitMode.FIT),
        )
    }

    private fun audioFromJson(json: JSONObject): AudioClip? {
        val uri = json.optString("uri").takeIf { it.isNotEmpty() } ?: return null
        val duration = json.optLong("sourceDurationUs", 0L).coerceAtLeast(0L)
        return AudioClip(
            id = json.optString("id", java.util.UUID.randomUUID().toString()),
            uri = uri,
            title = json.optString("title", ""),
            sourceDurationUs = duration,
            startOnTimelineUs = json.optLong("startOnTimelineUs", 0L),
            trimStartUs = json.optLong("trimStartUs", 0L),
            trimEndUs = json.optLong("trimEndUs", duration),
            volume = json.optDouble("volume", 1.0).toFloat(),
            fadeInUs = json.optLong("fadeInUs", 0L),
            fadeOutUs = json.optLong("fadeOutUs", 0L),
            loop = json.optBoolean("loop", false),
        )
    }

    private fun overlayFromJson(json: JSONObject): Overlay? {
        val id = json.optString("id", java.util.UUID.randomUUID().toString())
        val startUs = json.optLong("startUs", 0L)
        val endUs = json.optLong("endUs", 3_000_000L)
        val animationIn = enumOr(json.optString("animationIn"), OverlayAnimation.FADE)
        val animationOut = enumOr(json.optString("animationOut"), OverlayAnimation.FADE)
        val animationDurationUs = json.optLong("animationDurationUs", 400_000L)

        val transformJson = json.optJSONObject("transform")
        val transform = if (transformJson == null) {
            OverlayTransform()
        } else {
            OverlayTransform(
                x = transformJson.optDouble("x", 0.5).toFloat(),
                y = transformJson.optDouble("y", 0.5).toFloat(),
                scale = transformJson.optDouble("scale", 1.0).toFloat(),
                rotationDegrees = transformJson.optDouble("rotationDegrees", 0.0).toFloat(),
                opacity = transformJson.optDouble("opacity", 1.0).toFloat(),
            )
        }

        return when (json.optString("kind")) {
            "text" -> TextOverlay(
                id = id,
                text = json.optString("text", ""),
                style = textStyleFromJson(json.optJSONObject("style")),
                startUs = startUs,
                endUs = endUs,
                transform = transform,
                animationIn = animationIn,
                animationOut = animationOut,
                animationDurationUs = animationDurationUs,
            )
            "sticker" -> StickerOverlay(
                id = id,
                art = if (json.optString("artKind") == "shape") {
                    StickerArt.Shape(
                        shape = enumOr(json.optString("shape"), StickerShape.CIRCLE),
                        color = json.optInt("shapeColor", 0xFFFFFFFF.toInt()),
                    )
                } else {
                    StickerArt.Emoji(json.optString("glyph", "⭐"))
                },
                sizeFraction = json.optDouble("sizeFraction", 0.2).toFloat(),
                startUs = startUs,
                endUs = endUs,
                transform = transform,
                animationIn = animationIn,
                animationOut = animationOut,
                animationDurationUs = animationDurationUs,
            )
            else -> null
        }
    }

    private fun textStyleFromJson(json: JSONObject?): TextStyle {
        if (json == null) return TextStyle()
        val d = TextStyle()
        return TextStyle(
            font = enumOr(json.optString("font"), d.font),
            bold = json.optBoolean("bold", d.bold),
            italic = json.optBoolean("italic", d.italic),
            sizeFraction = json.optDouble("sizeFraction", d.sizeFraction.toDouble()).toFloat(),
            color = json.optInt("color", d.color),
            alignment = enumOr(json.optString("alignment"), d.alignment),
            letterSpacing = json.optDouble("letterSpacing", d.letterSpacing.toDouble()).toFloat(),
            lineSpacing = json.optDouble("lineSpacing", d.lineSpacing.toDouble()).toFloat(),
            strokeWidth = json.optDouble("strokeWidth", d.strokeWidth.toDouble()).toFloat(),
            strokeColor = json.optInt("strokeColor", d.strokeColor),
            shadowRadius = json.optDouble("shadowRadius", d.shadowRadius.toDouble()).toFloat(),
            shadowDx = json.optDouble("shadowDx", d.shadowDx.toDouble()).toFloat(),
            shadowDy = json.optDouble("shadowDy", d.shadowDy.toDouble()).toFloat(),
            shadowColor = json.optInt("shadowColor", d.shadowColor),
            backgroundColor = json.optInt("backgroundColor", d.backgroundColor),
            backgroundPadding = json.optDouble("backgroundPadding", d.backgroundPadding.toDouble()).toFloat(),
            backgroundRadius = json.optDouble("backgroundRadius", d.backgroundRadius.toDouble()).toFloat(),
        )
    }

    private fun floatsFromJson(array: JSONArray?): FloatArray {
        val out = FloatArray(8)
        if (array == null) return out
        for (i in 0 until minOf(8, array.length())) out[i] = array.optDouble(i, 0.0).toFloat()
        return out
    }

    private fun curveFromJson(array: JSONArray?): List<CurvePoint> {
        if (array == null) return emptyList()
        return (0 until array.length()).mapNotNull { index ->
            val point = array.optJSONObject(index) ?: return@mapNotNull null
            CurvePoint(
                point.optDouble("x", 0.0).toFloat(),
                point.optDouble("y", 0.0).toFloat(),
            )
        }
    }

    private inline fun <T> JSONArray?.mapObjects(transform: (JSONObject) -> T?): List<T> {
        if (this == null) return emptyList()
        return (0 until length()).mapNotNull { index ->
            optJSONObject(index)?.let(transform)
        }
    }

    private inline fun <reified T : Enum<T>> enumOr(name: String?, fallback: T): T =
        enumValues<T>().firstOrNull { it.name == name } ?: fallback
}
