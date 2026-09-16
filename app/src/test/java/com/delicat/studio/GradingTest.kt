package com.delicat.studio

import com.delicat.studio.engine.LutBaker
import com.delicat.studio.model.AdjustSpec
import com.delicat.studio.model.Adjustments
import com.delicat.studio.model.Looks
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The complaint this app began with was controls that appeared to do nothing.
 * A parameter that cannot change any colour is exactly that bug, so each one
 * is exercised rather than assumed to be connected.
 */
class GradingTest {

    private val probes = listOf(
        floatArrayOf(0.2f, 0.35f, 0.6f),
        floatArrayOf(0.5f, 0.5f, 0.5f),
        floatArrayOf(0.8f, 0.7f, 0.3f),
    )

    /** Parameters applied by the shader rather than the table; correctly inert here. */
    private val spatial = setOf(
        Adjustments.SHARPEN,
        Adjustments.BLUR,
        Adjustments.GRAIN,
        Adjustments.VIGNETTE,
        Adjustments.GLOW,
    )

    @Test
    fun `neutral adjustments leave colour untouched`() {
        val neutral = Adjustments()
        assertTrue(neutral.isNeutral)

        for (v in listOf(0f, 0.25f, 0.5f, 0.75f, 1f)) {
            val out = LutBaker.gradeOnce(neutral, v, v, v)
            assertEquals(v, out[0], 1e-6f)
            assertEquals(v, out[1], 1e-6f)
            assertEquals(v, out[2], 1e-6f)
        }
    }

    @Test
    fun `every colour parameter moves a pixel`() {
        for (spec in AdjustSpec.ALL) {
            if (spec.id in spatial) continue

            val nudged = Adjustments().set(spec.id, spec.max * 0.6f)
            val moved = probes.any { probe ->
                val before = LutBaker.gradeOnce(Adjustments(), probe[0], probe[1], probe[2])
                val after = LutBaker.gradeOnce(nudged, probe[0], probe[1], probe[2])
                (0..2).any { i -> kotlin.math.abs(before[i] - after[i]) > 1e-6f }
            }
            assertTrue("${spec.id} changed no colour", moved)
        }
    }

    @Test
    fun `every look is reachable and changes colour unless it is Original`() {
        assertEquals(Looks.ALL.size, Looks.ALL.map { it.id }.toSet().size)

        for (look in Looks.ALL) {
            assertEquals(look.id, Looks.get(look.id).id)
            assertTrue(look.name.isNotEmpty())

            val c = floatArrayOf(0.3f, 0.5f, 0.7f)
            val out = c.copyOf()
            look.apply(out)
            val unchanged = (0..2).all { kotlin.math.abs(out[it] - c[it]) < 1e-9f }
            assertEquals("${look.id} changed nothing", look.id == Looks.NONE_ID, unchanged)
        }
    }

    @Test
    fun `the baked table matches direct evaluation`() {
        // The preview samples this table; anything that reads it must agree
        // with the function it came from, or grading is done against colour
        // the export will not reproduce.
        val graded = Adjustments(
            exposure = 0.4f,
            contrast = 0.25f,
            saturation = -0.2f,
            temperature = 0.3f,
            highlights = -0.5f,
            shadows = 0.35f,
            hue = 24f,
            fade = 0.3f,
            lookId = "blockbuster",
            lookStrength = 0.8f,
        )

        val strip = LutBaker.bake(graded)
        val n = LutBaker.SIZE
        val scale = (n - 1).toFloat()

        for (b in 0 until n step 5) {
            for (g in 0 until n step 5) {
                for (r in 0 until n step 5) {
                    val x = (b % LutBaker.TILES_ACROSS) * n + r
                    val y = (b / LutBaker.TILES_ACROSS) * n + g
                    val packed = strip[y * LutBaker.stripWidth + x]

                    val exact = LutBaker.gradeOnce(graded, r / scale, g / scale, b / scale)
                    val fromStrip = floatArrayOf(
                        ((packed shr 16) and 0xFF) / 255f,
                        ((packed shr 8) and 0xFF) / 255f,
                        (packed and 0xFF) / 255f,
                    )

                    for (i in 0..2) {
                        // The strip is 8-bit and the function is float, so
                        // half a code value is the most they can differ by.
                        assertEquals(exact[i], fromStrip[i], 1f / 255f)
                    }
                }
            }
        }
    }

    @Test
    fun `a look is distinguishable from no look`() {
        val plain = LutBaker.gradeOnce(Adjustments(), 0.4f, 0.5f, 0.6f)
        val looked = LutBaker.gradeOnce(
            Adjustments(lookId = "blockbuster"),
            0.4f, 0.5f, 0.6f,
        )
        assertNotEquals(plain.toList(), looked.toList())
    }
}
