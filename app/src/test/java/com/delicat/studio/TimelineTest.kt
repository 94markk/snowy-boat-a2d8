package com.delicat.studio

import com.delicat.studio.engine.gl.Mat4
import com.delicat.studio.engine.gl.Placement
import com.delicat.studio.engine.gl.Transitions
import com.delicat.studio.model.Clip
import com.delicat.studio.model.FitMode
import com.delicat.studio.model.MediaKind
import com.delicat.studio.model.Project
import com.delicat.studio.model.Transition
import com.delicat.studio.model.TransitionType
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The timeline's arithmetic, which the strip on screen and the exporter both
 * depend on being the same.
 */
class TimelineTest {

    private fun clip(seconds: Double, transition: Transition = Transition.NONE): Clip {
        val us = (seconds * 1_000_000).toLong()
        return Clip(
            uri = "content://test/${seconds}",
            kind = MediaKind.VIDEO,
            sourceDurationUs = us,
            trimEndUs = us,
            transition = transition,
            sourceWidth = 1920,
            sourceHeight = 1080,
        )
    }

    @Test
    fun `clips without transitions lie end to end`() {
        val project = Project(clips = listOf(clip(2.0), clip(3.0), clip(1.0)))
        assertEquals(0L, project.startOf(0))
        assertEquals(2_000_000L, project.startOf(1))
        assertEquals(5_000_000L, project.startOf(2))
        assertEquals(6_000_000L, project.durationUs)
    }

    /**
     * The strip is laid out as one cell per clip, each as wide as the clip
     * minus whatever the transition into it hides. If those widths did not sum
     * to the duration, the playhead would drift from the preview by the total
     * of every transition on the timeline.
     */
    @Test
    fun `visible widths sum to the project duration`() {
        val project = Project(
            clips = listOf(
                clip(4.0),
                clip(4.0, Transition(TransitionType.DISSOLVE, 800_000L)),
                clip(4.0, Transition(TransitionType.WIPE_LEFT, 1_200_000L)),
            ),
        )

        val summed = project.clips.indices.sumOf { index ->
            project.clips[index].timelineDurationUs - project.overlapBefore(index)
        }
        assertEquals(project.durationUs, summed)
    }

    @Test
    fun `a transition cannot eat more than half of the shorter clip`() {
        val project = Project(
            clips = listOf(
                clip(0.6),
                clip(5.0, Transition(TransitionType.DISSOLVE, Transition.MAX_US)),
            ),
        )
        // Half of six hundred milliseconds, not the two seconds asked for.
        assertEquals(300_000L, project.overlapBefore(1))
        assertTrue(project.durationUs > 0L)
    }

    @Test
    fun `the playhead belongs to the incoming clip once it starts`() {
        val project = Project(
            clips = listOf(
                clip(3.0),
                clip(3.0, Transition(TransitionType.DISSOLVE, 1_000_000L)),
            ),
        )
        val overlap = project.overlapBefore(1)
        val secondStarts = project.startOf(1)

        assertEquals(1, project.clipIndexAt(secondStarts))
        assertEquals(0, project.clipIndexAt(secondStarts - 1))

        val midway = secondStarts + overlap / 2
        val composition = project.compositionAt(midway)
        assertTrue(composition.isTransitioning)
        assertEquals(1, composition.primaryIndex)
        assertEquals(0, composition.fromIndex)
        assertEquals(0.5f, composition.progress, 0.05f)
    }

    @Test
    fun `splitting produces two clips that still fill the same span`() {
        val project = Project(clips = listOf(clip(4.0)))
        val split = project.splitAt(1_500_000L)

        assertEquals(2, split.clips.size)
        assertEquals(project.durationUs, split.durationUs)
        assertEquals(TransitionType.NONE, split.clips[1].transition.type)
    }

    @Test
    fun `a split too close to an edge is refused rather than making a stub`() {
        val project = Project(clips = listOf(clip(4.0)))
        assertEquals(1, project.splitAt(0L).clips.size)
        assertEquals(1, project.splitAt(project.durationUs).clips.size)
    }

    /**
     * Every transition has to leave something on screen at every moment. A
     * gap here is a black frame in the middle of the finished video, which is
     * the sort of thing that only shows up after exporting.
     */
    @Test
    fun `a wipe reveals nothing before it starts`() {
        for (type in listOf(TransitionType.WIPE_LEFT, TransitionType.WIPE_RIGHT)) {
            val styles = Transitions.styles(type, 0f)
            for (x in listOf(0f, 0.5f, 1f)) {
                assertEquals(
                    "$type is already showing at $x",
                    0f, Transitions.reveal(styles.incoming.paint, x, 0.5f), 1e-4f,
                )
            }
        }
    }

    @Test
    fun `no transition leaves the canvas empty`() {
        for (type in TransitionType.entries) {
            var step = 0
            while (step <= 20) {
                val progress = step / 20f
                val styles = Transitions.styles(type, progress)

                val outgoingShows = styles.outgoing.visible &&
                    styles.outgoing.paint.alpha > 0.01f &&
                    styles.outgoing.paint.overlayAmount < 0.999f
                val incomingShows = styles.incoming.visible &&
                    styles.incoming.paint.alpha > 0.01f &&
                    styles.incoming.paint.overlayAmount < 0.999f
                val washedToColour = styles.outgoing.paint.overlayAmount > 0.999f ||
                    styles.incoming.paint.overlayAmount > 0.999f

                assertTrue(
                    "$type at $progress draws nothing",
                    outgoingShows || incomingShows || washedToColour,
                )
                step++
            }
        }
    }

    @Test
    fun `a finished transition shows the incoming clip and nothing of the old one`() {
        for (type in TransitionType.entries) {
            val styles = Transitions.styles(type, 1f)
            assertEquals("$type does not finish opaque", 1f, styles.incoming.paint.alpha, 1e-5f)
            assertEquals(
                "$type does not finish clear of its wash",
                0f, styles.incoming.paint.overlayAmount, 1e-5f,
            )
            // A finished wipe must have crossed the whole canvas, corners
            // included, or the file ends up with a sliver of the old clip.
            for (x in listOf(0f, 0.5f, 1f)) {
                for (yy in listOf(0f, 0.5f, 1f)) {
                    assertEquals(
                        "$type has not finished wiping at ($x, $yy)",
                        1f, Transitions.reveal(styles.incoming.paint, x, yy), 1e-4f,
                    )
                }
            }
        }
    }
}

/** Fit, fill and rotation, which decide what the canvas actually contains. */
class PlacementTest {

    private fun scaleOf(placement: Placement): Pair<Float, Float> {
        val matrix = FloatArray(16)
        placement.modelViewProjection(matrix)
        // Column-major: the x scale is [0] and the y scale is [5].
        return matrix[0] to matrix[5]
    }

    @Test
    fun `fit keeps the whole frame inside the canvas`() {
        val wideInTall = Placement(
            canvasWidth = 1080, canvasHeight = 1920,
            sourceWidth = 1920, sourceHeight = 1080,
            fit = FitMode.FIT,
        )
        val (x, y) = scaleOf(wideInTall)
        assertEquals(1f, x, 1e-4f)
        assertTrue("landscape in portrait should be letterboxed", y < 1f)
    }

    @Test
    fun `fill covers the canvas and lets the overflow be clipped`() {
        val wideInTall = Placement(
            canvasWidth = 1080, canvasHeight = 1920,
            sourceWidth = 1920, sourceHeight = 1080,
            fit = FitMode.FILL,
        )
        val (x, y) = scaleOf(wideInTall)
        assertEquals(1f, y, 1e-4f)
        assertTrue("fill should overflow the narrow axis", x > 1f)
    }

    @Test
    fun `a matching aspect needs no scaling either way`() {
        for (mode in FitMode.entries) {
            val (x, y) = scaleOf(
                Placement(
                    canvasWidth = 1080, canvasHeight = 1920,
                    sourceWidth = 540, sourceHeight = 960,
                    fit = mode,
                ),
            )
            assertEquals(1f, x, 1e-4f)
            assertEquals(1f, y, 1e-4f)
        }
    }

    @Test
    fun `a quarter turn swaps which edge is the long one`() {
        val upright = Placement(
            canvasWidth = 1080, canvasHeight = 1920,
            sourceWidth = 1920, sourceHeight = 1080,
        )
        assertEquals(1920, upright.displayWidth)
        assertEquals(1080, upright.displayHeight)

        val turned = upright.copy(quarterTurns = 1)
        assertEquals(1080, turned.displayWidth)
        assertEquals(1920, turned.displayHeight)

        // And a turned landscape clip then fits a portrait canvas exactly.
        val (x, y) = scaleOf(turned)
        assertEquals(1f, x, 1e-4f)
        assertEquals(1f, y, 1e-4f)
    }

    @Test
    fun `negative and oversized turns are treated as the turn they amount to`() {
        val base = Placement(
            canvasWidth = 1080, canvasHeight = 1920,
            sourceWidth = 1920, sourceHeight = 1080,
        )
        assertEquals(base.copy(quarterTurns = 1).displayWidth, base.copy(quarterTurns = -3).displayWidth)
        assertEquals(base.copy(quarterTurns = 1).displayWidth, base.copy(quarterTurns = 5).displayWidth)
    }
}

/**
 * Which pixel of the source ends up under which corner of the canvas.
 *
 * Written because this is genuinely hard to hold in one's head: the rotation
 * turns the coordinate being sampled rather than the picture, so its sign is
 * the opposite of the one the user asked for, and the flip that corrects a
 * bitmap's row order has to be applied after it rather than before. Getting
 * either wrong produces a picture that is rotated, just not by what was asked.
 */
class TextureMatrixTest {

    private fun sample(turns: Int, flip: Boolean, quadX: Float, quadY: Float): Pair<Float, Float> {
        val placement = Placement(
            canvasWidth = 1080, canvasHeight = 1920,
            sourceWidth = 1920, sourceHeight = 1080,
            quarterTurns = turns,
            flipVertically = flip,
        )
        val matrix = FloatArray(16)
        placement.textureMatrix(null, matrix, FloatArray(16))

        val out = FloatArray(4)
        Mat4.transform(out, matrix, quadX, quadY, 0f, 1f)
        return out[0] to out[1]
    }

    private fun assertCorner(expected: Pair<Float, Float>, actual: Pair<Float, Float>, what: String) {
        assertEquals("$what x", expected.first, actual.first, 1e-4f)
        assertEquals("$what y", expected.second, actual.second, 1e-4f)
    }

    /**
     * A still's first row is its top, which is the opposite end from where the
     * quad's first row lands, so an unrotated photo samples the bottom of the
     * image at the bottom of the screen by reading v = 1.
     */
    @Test
    fun `an unrotated still is the right way up`() {
        assertCorner(0f to 1f, sample(0, flip = true, 0f, 0f), "bottom left")
        assertCorner(1f to 0f, sample(0, flip = true, 1f, 1f), "top right")
    }

    @Test
    fun `an unrotated video frame is sampled as it arrives`() {
        assertCorner(0f to 0f, sample(0, flip = false, 0f, 0f), "bottom left")
        assertCorner(1f to 1f, sample(0, flip = false, 1f, 1f), "top right")
    }

    /**
     * One turn clockwise puts the source's bottom right corner at the bottom
     * left of the canvas, for a still and for a video frame alike. That the
     * two agree is the point: the same rotation control drives both.
     */
    @Test
    fun `a quarter turn goes clockwise for both kinds of source`() {
        assertCorner(1f to 1f, sample(1, flip = true, 0f, 0f), "still, one turn")
        assertCorner(1f to 0f, sample(1, flip = false, 0f, 0f), "video, one turn")
    }

    @Test
    fun `turning four times comes back to where it started`() {
        for (flip in listOf(true, false)) {
            for (corner in listOf(0f to 0f, 1f to 0f, 0f to 1f, 1f to 1f)) {
                assertCorner(
                    sample(0, flip, corner.first, corner.second),
                    sample(4, flip, corner.first, corner.second),
                    "flip=$flip corner=$corner",
                )
            }
        }
    }

    @Test
    fun `every turn keeps the whole source inside the frame`() {
        for (turns in 0..3) {
            for (flip in listOf(true, false)) {
                for (x in listOf(0f, 1f)) {
                    for (y in listOf(0f, 1f)) {
                        val (u, v) = sample(turns, flip, x, y)
                        assertTrue("turns=$turns flip=$flip maps outside the texture", u in -1e-4f..1.0001f)
                        assertTrue("turns=$turns flip=$flip maps outside the texture", v in -1e-4f..1.0001f)
                    }
                }
            }
        }
    }
}
