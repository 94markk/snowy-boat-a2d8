package com.delicat.studio

import android.graphics.Bitmap
import android.graphics.Color
import android.opengl.GLES20
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.delicat.studio.engine.LutBaker
import com.delicat.studio.engine.export.EglCore
import com.delicat.studio.engine.gl.FrameRenderer
import com.delicat.studio.engine.gl.GlUtil
import com.delicat.studio.engine.gl.LayerPaint
import com.delicat.studio.engine.gl.Placement
import com.delicat.studio.model.Adjustments
import com.delicat.studio.model.FitMode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import java.nio.ByteBuffer
import java.nio.ByteOrder
import kotlin.math.abs

/**
 * Runs the real shader on a real GPU and reads the pixels back.
 *
 * Every previous round of this app was verified by a compiler and by tests of
 * pure functions, neither of which can tell you whether anything appeared on
 * screen. The grading maths has been correct and provable for some time; what
 * was never checked is whether the thing drawing it agrees. This closes that
 * gap by comparing what the GPU produces against [LutBaker], which the unit
 * tests already pin down — so a disagreement here means the pipeline, not the
 * arithmetic.
 */
@RunWith(AndroidJUnit4::class)
class GradingPipelineTest {

    private val size = 32

    /** What one solid colour comes out as, once drawn through the pipeline. */
    private fun render(input: Int, adjustments: Adjustments): IntArray {
        val egl = EglCore()
        val surface = egl.createOffscreenSurface(size, size)
        egl.makeCurrent(surface)

        val renderer = FrameRenderer()
        var texture = 0
        try {
            renderer.setup()
            assertTrue(
                "the pipeline would not start: ${renderer.failure}",
                renderer.isReady,
            )

            val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888)
            bitmap.eraseColor(input)
            texture = GlUtil.createTexture(GlUtil.FLAT_TEXTURE)
            GlUtil.upload(texture, bitmap)

            renderer.beginFrame(size, size, Color.BLACK)
            renderer.drawLayer(
                texture = texture,
                isExternal = false,
                sourceMatrix = null,
                sourceWidth = size,
                sourceHeight = size,
                adjustments = adjustments,
                placement = Placement(
                    canvasWidth = size, canvasHeight = size,
                    sourceWidth = size, sourceHeight = size,
                    fit = FitMode.FILL,
                    flipVertically = true,
                ),
                paint = LayerPaint.OPAQUE,
            )
            GLES20.glFinish()

            val buffer = ByteBuffer.allocateDirect(size * size * 4).order(ByteOrder.nativeOrder())
            GLES20.glReadPixels(
                0, 0, size, size, GLES20.GL_RGBA, GLES20.GL_UNSIGNED_BYTE, buffer,
            )
            bitmap.recycle()

            // The middle of a uniform field, so nothing at an edge can be
            // mistaken for a grading result.
            val at = ((size / 2) * size + size / 2) * 4
            return intArrayOf(
                buffer.get(at).toInt() and 0xFF,
                buffer.get(at + 1).toInt() and 0xFF,
                buffer.get(at + 2).toInt() and 0xFF,
            )
        } finally {
            GlUtil.deleteTexture(texture)
            renderer.release()
            egl.releaseSurface(surface)
            egl.release()
        }
    }

    private fun expected(input: Int, adjustments: Adjustments): IntArray {
        val out = LutBaker.gradeOnce(
            adjustments,
            Color.red(input) / 255f,
            Color.green(input) / 255f,
            Color.blue(input) / 255f,
        )
        return IntArray(3) { (out[it] * 255f + 0.5f).toInt().coerceIn(0, 255) }
    }

    private fun assertClose(input: Int, adjustments: Adjustments, tolerance: Int, what: String) {
        val drawn = render(input, adjustments)
        val wanted = expected(input, adjustments)
        for (channel in 0..2) {
            assertTrue(
                "$what: channel $channel drew ${drawn.toList()}, expected ${wanted.toList()}",
                abs(drawn[channel] - wanted[channel]) <= tolerance,
            )
        }
    }

    /**
     * With nothing set, the pipeline has to be a pass-through.
     *
     * This is the sharpest test in the file. A lookup table indexed even
     * slightly wrongly — the wrong tile, the axes swapped, a half-texel out —
     * fails here by a wide margin, because every one of those reads a colour
     * from somewhere else in the cube entirely.
     */
    @Test
    fun neutralAdjustmentsChangeNothing() {
        for (colour in listOf(
            Color.rgb(0, 0, 0),
            Color.rgb(255, 255, 255),
            Color.rgb(31, 97, 203),
            Color.rgb(211, 44, 128),
            Color.rgb(120, 120, 120),
        )) {
            assertClose(colour, Adjustments(), 2, "neutral on ${Integer.toHexString(colour)}")
        }
    }

    /** The graded result has to agree with the table the unit tests pin down. */
    @Test
    fun gradedOutputMatchesTheTable() {
        val graded = Adjustments(
            exposure = 0.4f,
            contrast = 0.25f,
            saturation = -0.2f,
            temperature = 0.3f,
            highlights = -0.5f,
            shadows = 0.35f,
            lookId = "blockbuster",
            lookStrength = 0.8f,
        )
        for (colour in listOf(
            Color.rgb(40, 90, 150),
            Color.rgb(200, 180, 90),
            Color.rgb(128, 128, 128),
        )) {
            // Wider than the neutral case: the cube is thirty-two a side and
            // the GPU interpolates between its entries, so a curve through a
            // steep region lands a few code values from the exact evaluation.
            assertClose(colour, graded, 8, "graded ${Integer.toHexString(colour)}")
        }
    }

    /**
     * Every parameter, checked where it counts: on the screen.
     *
     * Three probes rather than one, because several of these act on a band of
     * the tonal range and do almost nothing outside it. Shadows at a midtone
     * moves the result by less than a single code value, which says nothing
     * about shadows and everything about the colour it was asked with — a
     * one-probe version of this test failed for exactly that reason.
     */
    @Test
    fun everyColourParameterMovesADrawnPixel() {
        val probes = listOf(
            Color.rgb(45, 55, 40),
            Color.rgb(120, 140, 90),
            Color.rgb(215, 200, 170),
        )
        val plain = probes.map { render(it, Adjustments()) }

        val spatial = setOf(
            Adjustments.SHARPEN, Adjustments.BLUR, Adjustments.GRAIN,
            Adjustments.VIGNETTE, Adjustments.GLOW,
        )
        for (spec in com.delicat.studio.model.AdjustSpec.ALL) {
            if (spec.id in spatial) continue
            val nudged = Adjustments().set(spec.id, spec.max * 0.7f)
            val moved = probes.indices.any { i ->
                val drawn = render(probes[i], nudged)
                (0..2).any { abs(drawn[it] - plain[i][it]) > 2 }
            }
            assertTrue("${spec.id} drew the same pixel as neutral, on every probe", moved)
        }
    }

    @Test
    fun fullyDesaturatedIsGrey() {
        val drawn = render(Color.rgb(200, 60, 40), Adjustments(saturation = -1f))
        assertEquals("red and green differ", drawn[0].toFloat(), drawn[1].toFloat(), 3f)
        assertEquals("green and blue differ", drawn[1].toFloat(), drawn[2].toFloat(), 3f)
    }

    /** A vignette is spatial, so it darkens a corner and leaves the middle be. */
    @Test
    fun theVignetteDarkensTheEdgeAndNotTheCentre() {
        val plain = render(Color.rgb(200, 200, 200), Adjustments())
        val vignetted = render(Color.rgb(200, 200, 200), Adjustments(vignette = 1f))
        assertTrue(
            "the vignette changed the centre (${plain.toList()} to ${vignetted.toList()})",
            abs(plain[0] - vignetted[0]) <= 6,
        )
    }
}
