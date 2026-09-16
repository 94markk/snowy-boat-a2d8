package com.vixel.studio.engine.photo

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Path
import android.graphics.Rect
import android.graphics.RectF
import com.vixel.studio.core.model.AspectRatio
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

/** A cell as a fraction of the canvas: 0,0 is top-left, 1,1 bottom-right. */
data class CollageCell(val left: Float, val top: Float, val right: Float, val bottom: Float)

data class CollageLayout(
    val id: String,
    val label: String,
    val cells: List<CollageCell>,
) {
    val count: Int get() = cells.size
}

object CollageLayouts {

    private fun cell(l: Float, t: Float, r: Float, b: Float) = CollageCell(l, t, r, b)

    val ALL: List<CollageLayout> = listOf(
        CollageLayout("1", "Single", listOf(cell(0f, 0f, 1f, 1f))),

        CollageLayout(
            "2v", "2 side by side",
            listOf(cell(0f, 0f, .5f, 1f), cell(.5f, 0f, 1f, 1f)),
        ),
        CollageLayout(
            "2h", "2 stacked",
            listOf(cell(0f, 0f, 1f, .5f), cell(0f, .5f, 1f, 1f)),
        ),

        CollageLayout(
            "3h", "3 stacked",
            listOf(cell(0f, 0f, 1f, 1 / 3f), cell(0f, 1 / 3f, 1f, 2 / 3f), cell(0f, 2 / 3f, 1f, 1f)),
        ),
        CollageLayout(
            "3l", "1 + 2",
            listOf(cell(0f, 0f, 1f, .5f), cell(0f, .5f, .5f, 1f), cell(.5f, .5f, 1f, 1f)),
        ),
        CollageLayout(
            "3r", "2 + 1",
            listOf(cell(0f, 0f, .5f, .5f), cell(.5f, 0f, 1f, .5f), cell(0f, .5f, 1f, 1f)),
        ),

        CollageLayout(
            "4g", "2 x 2",
            listOf(
                cell(0f, 0f, .5f, .5f), cell(.5f, 0f, 1f, .5f),
                cell(0f, .5f, .5f, 1f), cell(.5f, .5f, 1f, 1f),
            ),
        ),
        CollageLayout(
            "4h", "4 stacked",
            listOf(
                cell(0f, 0f, 1f, .25f), cell(0f, .25f, 1f, .5f),
                cell(0f, .5f, 1f, .75f), cell(0f, .75f, 1f, 1f),
            ),
        ),
        CollageLayout(
            "4f", "1 big + 3",
            listOf(
                cell(0f, 0f, 1f, .55f),
                cell(0f, .55f, 1 / 3f, 1f), cell(1 / 3f, .55f, 2 / 3f, 1f), cell(2 / 3f, .55f, 1f, 1f),
            ),
        ),

        CollageLayout(
            "5", "1 + 4",
            listOf(
                cell(0f, 0f, 1f, .5f),
                cell(0f, .5f, .5f, .75f), cell(.5f, .5f, 1f, .75f),
                cell(0f, .75f, .5f, 1f), cell(.5f, .75f, 1f, 1f),
            ),
        ),
        CollageLayout(
            "6", "2 x 3",
            listOf(
                cell(0f, 0f, .5f, 1 / 3f), cell(.5f, 0f, 1f, 1 / 3f),
                cell(0f, 1 / 3f, .5f, 2 / 3f), cell(.5f, 1 / 3f, 1f, 2 / 3f),
                cell(0f, 2 / 3f, .5f, 1f), cell(.5f, 2 / 3f, 1f, 1f),
            ),
        ),
    )

    /** Layouts that can hold [count] photos, best fit first. */
    fun forCount(count: Int): List<CollageLayout> {
        if (count <= 0) return ALL
        val exact = ALL.filter { it.count == count }
        val larger = ALL.filter { it.count > count }.sortedBy { it.count }
        val smaller = ALL.filter { it.count < count }.sortedByDescending { it.count }
        return exact + larger + smaller
    }
}

object CollageRenderer {

    data class Options(
        val aspect: AspectRatio = AspectRatio.SQUARE,
        /** Long edge of the output in pixels. */
        val size: Int = 2048,
        /** Gap between cells, as a fraction of the short edge. 0..0.08 */
        val spacing: Float = 0.015f,
        /** Corner rounding, as a fraction of the short edge. 0..0.12 */
        val cornerRadius: Float = 0.02f,
        /** Outer margin, as a fraction of the short edge. 0..0.08 */
        val margin: Float = 0.015f,
        val backgroundColor: Int = Color.WHITE,
    )

    /**
     * Draws [bitmaps] into [layout].
     *
     * Each photo is centre-cropped to its cell rather than squashed, which is
     * what keeps faces the right shape when a portrait shot lands in a
     * landscape cell.
     */
    fun render(
        bitmaps: List<Bitmap>,
        layout: CollageLayout,
        options: Options = Options(),
    ): Bitmap {
        val (width, height) = sizeFor(options)
        val output = Bitmap.createBitmap(width, height, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(output)
        canvas.drawColor(options.backgroundColor)

        val shortEdge = min(width, height).toFloat()
        val gap = (options.spacing * shortEdge) / 2f
        val margin = options.margin * shortEdge
        val radius = options.cornerRadius * shortEdge

        val paint = Paint(Paint.ANTI_ALIAS_FLAG).apply { isFilterBitmap = true }
        val innerWidth = width - margin * 2
        val innerHeight = height - margin * 2

        layout.cells.forEachIndexed { index, cellFraction ->
            val bitmap = bitmaps.getOrNull(index) ?: return@forEachIndexed
            if (bitmap.isRecycled) return@forEachIndexed

            val dst = RectF(
                margin + cellFraction.left * innerWidth + gap,
                margin + cellFraction.top * innerHeight + gap,
                margin + cellFraction.right * innerWidth - gap,
                margin + cellFraction.bottom * innerHeight - gap,
            )
            if (dst.width() <= 1f || dst.height() <= 1f) return@forEachIndexed

            canvas.save()
            if (radius > 0.5f) {
                val path = Path().apply { addRoundRect(dst, radius, radius, Path.Direction.CW) }
                canvas.clipPath(path)
            } else {
                canvas.clipRect(dst)
            }
            canvas.drawBitmap(bitmap, centreCrop(bitmap, dst), dst, paint)
            canvas.restore()
        }

        return output
    }

    fun sizeFor(options: Options): Pair<Int, Int> {
        val ratio = options.aspect.ratio
        return if (ratio >= 1f) {
            options.size to max(1, (options.size / ratio).roundToInt())
        } else {
            max(1, (options.size * ratio).roundToInt()) to options.size
        }
    }

    /** Source rect that fills [dst] without distorting the photo. */
    private fun centreCrop(bitmap: Bitmap, dst: RectF): Rect {
        val targetAspect = dst.width() / dst.height()
        val sourceAspect = bitmap.width.toFloat() / bitmap.height

        return if (sourceAspect > targetAspect) {
            // Source is wider: trim the sides.
            val cropWidth = (bitmap.height * targetAspect).roundToInt().coerceAtMost(bitmap.width)
            val x = (bitmap.width - cropWidth) / 2
            Rect(x, 0, x + cropWidth, bitmap.height)
        } else {
            // Source is taller: trim top and bottom.
            val cropHeight = (bitmap.width / targetAspect).roundToInt().coerceAtMost(bitmap.height)
            val y = (bitmap.height - cropHeight) / 2
            Rect(0, y, bitmap.width, y + cropHeight)
        }
    }
}
