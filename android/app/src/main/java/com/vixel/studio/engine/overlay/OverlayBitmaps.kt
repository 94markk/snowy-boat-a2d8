package com.vixel.studio.engine.overlay

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Path
import android.graphics.RectF
import android.graphics.Typeface
import android.text.Layout
import android.text.StaticLayout
import android.text.TextPaint
import com.vixel.studio.core.model.Overlay
import com.vixel.studio.core.model.StickerArt
import com.vixel.studio.core.model.StickerOverlay
import com.vixel.studio.core.model.StickerShape
import com.vixel.studio.core.model.TextAlignment
import com.vixel.studio.core.model.TextFont
import com.vixel.studio.core.model.TextOverlay
import com.vixel.studio.core.model.TextStyle
import kotlin.math.cos
import kotlin.math.max
import kotlin.math.roundToInt
import kotlin.math.sin

/**
 * Rasterises overlays with the platform 2D canvas.
 *
 * Everything is sized from canvas height rather than in absolute pixels, so
 * the same project renders identically at preview resolution and at 4K —
 * text baked at one size and scaled to another is the usual reason captions
 * look soft or land in the wrong place in an export.
 */
object OverlayBitmaps {

    /** Guards against a huge font size or a long line blowing up memory. */
    private const val MAX_EDGE = 2048

    /**
     * Identity of the rendered result. Two overlays with the same key produce
     * the same bitmap, which is what makes caching safe.
     */
    fun cacheKey(overlay: Overlay, canvasWidth: Int, canvasHeight: Int): String = when (overlay) {
        is TextOverlay -> "t:${overlay.text}:${overlay.style}:$canvasWidth:$canvasHeight"
        is StickerOverlay -> "s:${overlay.art}:${overlay.sizeFraction}:$canvasWidth:$canvasHeight"
    }

    fun render(overlay: Overlay, canvasWidth: Int, canvasHeight: Int): Bitmap? = when (overlay) {
        is TextOverlay -> renderText(overlay, canvasWidth, canvasHeight)
        is StickerOverlay -> renderSticker(overlay, canvasHeight)
    }

    // ------------------------------------------------------------------ text

    private fun renderText(overlay: TextOverlay, canvasWidth: Int, canvasHeight: Int): Bitmap? {
        val text = overlay.text
        if (text.isEmpty()) return null

        val style = overlay.style
        val fontSize = (style.sizeFraction * canvasHeight).coerceIn(8f, 400f)

        val paint = TextPaint(Paint.ANTI_ALIAS_FLAG).apply {
            typeface = typefaceFor(style)
            textSize = fontSize
            color = style.color
            letterSpacing = style.letterSpacing
        }

        // Wrap to most of the canvas width so long captions break instead of
        // running off the edge.
        val maxWidth = (canvasWidth * 0.9f).roundToInt().coerceAtLeast(16)
        val layout = buildLayout(text, paint, maxWidth, style)

        val padding = style.backgroundPadding * fontSize
        val hasBackground = Color.alpha(style.backgroundColor) > 0
        val strokePad = style.strokeWidth * fontSize
        val shadowPad = if (style.shadowRadius > 0f) {
            (style.shadowRadius + maxOf(kotlin.math.abs(style.shadowDx), kotlin.math.abs(style.shadowDy))) * fontSize
        } else {
            0f
        }
        val outerPad = (if (hasBackground) padding else 0f) + strokePad + shadowPad

        val width = (layout.width + outerPad * 2).roundToInt().coerceIn(1, MAX_EDGE)
        val height = (layout.height + outerPad * 2).roundToInt().coerceIn(1, MAX_EDGE)

        val bitmap = Bitmap.createBitmap(width, height, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(bitmap)

        if (hasBackground) {
            val plate = RectF(
                shadowPad + strokePad,
                shadowPad + strokePad,
                width - shadowPad - strokePad,
                height - shadowPad - strokePad,
            )
            val radius = style.backgroundRadius * fontSize
            canvas.drawRoundRect(
                plate, radius, radius,
                Paint(Paint.ANTI_ALIAS_FLAG).apply { color = style.backgroundColor },
            )
        }

        canvas.save()
        canvas.translate(outerPad, outerPad)

        // Outline first, then the fill on top, so the stroke sits outside the
        // glyph rather than eating into it.
        if (style.strokeWidth > 0f) {
            val strokePaint = TextPaint(paint).apply {
                this.style = Paint.Style.STROKE
                strokeWidth = style.strokeWidth * fontSize * 2f
                strokeJoin = Paint.Join.ROUND
                color = style.strokeColor
                clearShadowLayer()
            }
            buildLayout(text, strokePaint, maxWidth, style).draw(canvas)
        }

        if (style.shadowRadius > 0f) {
            paint.setShadowLayer(
                style.shadowRadius * fontSize,
                style.shadowDx * fontSize,
                style.shadowDy * fontSize,
                style.shadowColor,
            )
        }
        buildLayout(text, paint, maxWidth, style).draw(canvas)
        canvas.restore()

        return bitmap
    }

    private fun buildLayout(
        text: String,
        paint: TextPaint,
        maxWidth: Int,
        style: TextStyle,
    ): StaticLayout {
        val alignment = when (style.alignment) {
            TextAlignment.START -> Layout.Alignment.ALIGN_NORMAL
            TextAlignment.CENTER -> Layout.Alignment.ALIGN_CENTER
            TextAlignment.END -> Layout.Alignment.ALIGN_OPPOSITE
        }
        return StaticLayout.Builder
            .obtain(text, 0, text.length, paint, maxWidth)
            .setAlignment(alignment)
            .setLineSpacing(0f, style.lineSpacing)
            .setIncludePad(false)
            .build()
    }

    private fun typefaceFor(style: TextStyle): Typeface {
        val family = when (style.font) {
            TextFont.SANS -> "sans-serif"
            TextFont.SANS_CONDENSED -> "sans-serif-condensed"
            TextFont.SERIF -> "serif"
            TextFont.MONOSPACE -> "monospace"
            TextFont.CURSIVE -> "cursive"
        }
        val attrs = when {
            style.bold && style.italic -> Typeface.BOLD_ITALIC
            style.bold -> Typeface.BOLD
            style.italic -> Typeface.ITALIC
            else -> Typeface.NORMAL
        }
        return Typeface.create(Typeface.create(family, Typeface.NORMAL), attrs)
    }

    // --------------------------------------------------------------- sticker

    private fun renderSticker(overlay: StickerOverlay, canvasHeight: Int): Bitmap? {
        val size = (overlay.sizeFraction * canvasHeight).roundToInt().coerceIn(16, MAX_EDGE)
        return when (val art = overlay.art) {
            is StickerArt.Emoji -> renderEmoji(art.glyph, size)
            is StickerArt.Shape -> renderShape(art.shape, art.color, size)
        }
    }

    private fun renderEmoji(glyph: String, size: Int): Bitmap? {
        if (glyph.isEmpty()) return null
        val paint = TextPaint(Paint.ANTI_ALIAS_FLAG).apply {
            textSize = size * 0.82f
            textAlign = Paint.Align.CENTER
        }
        val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(bitmap)
        val metrics = paint.fontMetrics
        // Centre on the glyph's visual box rather than the text baseline.
        val baseline = size / 2f - (metrics.ascent + metrics.descent) / 2f
        canvas.drawText(glyph, size / 2f, baseline, paint)
        return bitmap
    }

    private fun renderShape(shape: StickerShape, color: Int, size: Int): Bitmap {
        val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(bitmap)
        val paint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            this.color = color
            style = Paint.Style.FILL
        }
        val s = size.toFloat()
        val c = s / 2f

        when (shape) {
            StickerShape.CIRCLE -> canvas.drawCircle(c, c, c * 0.92f, paint)

            StickerShape.SQUARE -> canvas.drawRoundRect(
                RectF(s * 0.06f, s * 0.06f, s * 0.94f, s * 0.94f), s * 0.12f, s * 0.12f, paint,
            )

            StickerShape.TRIANGLE -> canvas.drawPath(
                Path().apply {
                    moveTo(c, s * 0.08f)
                    lineTo(s * 0.94f, s * 0.9f)
                    lineTo(s * 0.06f, s * 0.9f)
                    close()
                },
                paint,
            )

            StickerShape.STAR -> canvas.drawPath(starPath(c, c, c * 0.92f, c * 0.4f, 5), paint)

            StickerShape.BURST -> canvas.drawPath(starPath(c, c, c * 0.95f, c * 0.62f, 12), paint)

            StickerShape.HEART -> canvas.drawPath(
                Path().apply {
                    // Two arcs meeting at a point, the standard heart construction.
                    moveTo(c, s * 0.92f)
                    cubicTo(s * -0.2f, s * 0.52f, s * 0.18f, s * 0.04f, c, s * 0.3f)
                    cubicTo(s * 0.82f, s * 0.04f, s * 1.2f, s * 0.52f, c, s * 0.92f)
                    close()
                },
                paint,
            )

            StickerShape.ARROW -> canvas.drawPath(
                Path().apply {
                    moveTo(s * 0.05f, s * 0.38f)
                    lineTo(s * 0.55f, s * 0.38f)
                    lineTo(s * 0.55f, s * 0.16f)
                    lineTo(s * 0.95f, c)
                    lineTo(s * 0.55f, s * 0.84f)
                    lineTo(s * 0.55f, s * 0.62f)
                    lineTo(s * 0.05f, s * 0.62f)
                    close()
                },
                paint,
            )

            StickerShape.SPEECH_BUBBLE -> {
                canvas.drawRoundRect(
                    RectF(s * 0.06f, s * 0.12f, s * 0.94f, s * 0.7f), s * 0.16f, s * 0.16f, paint,
                )
                canvas.drawPath(
                    Path().apply {
                        moveTo(s * 0.28f, s * 0.68f)
                        lineTo(s * 0.3f, s * 0.94f)
                        lineTo(s * 0.52f, s * 0.68f)
                        close()
                    },
                    paint,
                )
            }
        }
        return bitmap
    }

    private fun starPath(
        cx: Float,
        cy: Float,
        outer: Float,
        inner: Float,
        points: Int,
    ): Path = Path().apply {
        val step = Math.PI / points
        var angle = -Math.PI / 2
        moveTo(cx + (outer * cos(angle)).toFloat(), cy + (outer * sin(angle)).toFloat())
        for (i in 1 until points * 2) {
            angle += step
            val radius = if (i % 2 == 0) outer else inner
            lineTo(cx + (radius * cos(angle)).toFloat(), cy + (radius * sin(angle)).toFloat())
        }
        close()
    }

    /** Keeps a bitmap within the texture budget. */
    fun clampSize(width: Int, height: Int): Pair<Int, Int> {
        val longest = max(width, height)
        if (longest <= MAX_EDGE) return width to height
        val scale = MAX_EDGE.toFloat() / longest
        return max(1, (width * scale).roundToInt()) to max(1, (height * scale).roundToInt())
    }
}
