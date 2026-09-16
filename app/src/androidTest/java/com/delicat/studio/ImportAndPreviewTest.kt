package com.delicat.studio

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.net.Uri
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.delicat.studio.engine.EditorEngine
import com.delicat.studio.engine.MediaProbe
import com.delicat.studio.engine.preview.LayerSource
import com.delicat.studio.model.Adjustments
import com.delicat.studio.model.MediaKind
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import java.io.File

/**
 * Imports a real file and follows it all the way to the drawn frame.
 *
 * The complaint this app keeps coming back with is that nothing works, and
 * every previous answer to it was reasoning about code rather than running it.
 * This writes an actual photo to disk, hands it to the editor the way the
 * picker would, and asserts that a clip appears, that a frame is decoded for
 * it, and that moving a parameter reaches the thing that draws it.
 */
@RunWith(AndroidJUnit4::class)
class ImportAndPreviewTest {

    private val context = InstrumentationRegistry.getInstrumentation().targetContext
    private lateinit var engine: EditorEngine
    private lateinit var scope: CoroutineScope
    private lateinit var photo: File

    @Before
    fun setUp() {
        photo = File(context.cacheDir, "probe-${System.nanoTime()}.jpg")
        val bitmap = Bitmap.createBitmap(640, 360, Bitmap.Config.ARGB_8888)
        Canvas(bitmap).apply {
            drawColor(Color.rgb(30, 90, 180))
            drawCircle(320f, 180f, 120f, Paint().apply { color = Color.rgb(230, 190, 60) })
        }
        photo.outputStream().use { bitmap.compress(Bitmap.CompressFormat.JPEG, 92, it) }
        bitmap.recycle()

        scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
        onMain { engine = EditorEngine(context, scope) }
    }

    @After
    fun tearDown() {
        onMain { engine.release() }
        photo.delete()
    }

    private fun onMain(block: () -> Unit) =
        InstrumentationRegistry.getInstrumentation().runOnMainSync(block)

    /** Polls rather than sleeps, so a passing run costs only what it needs. */
    private fun waitFor(what: String, timeoutMs: Long = 8_000, condition: () -> Boolean) {
        val deadline = System.currentTimeMillis() + timeoutMs
        while (System.currentTimeMillis() < deadline) {
            var met = false
            onMain { met = condition() }
            if (met) return
            Thread.sleep(40)
        }
        throw AssertionError("timed out waiting for $what")
    }

    /**
     * A JPEG on disk has no content provider to report its type, so this also
     * covers the fallback that previously did not exist: the old probe asked a
     * metadata reader whether the file had a video track, a photo said no, and
     * every image import failed silently.
     */
    @Test
    fun aPhotoIsRecognisedAsAPhoto() {
        val info = MediaProbe.probe(context, Uri.fromFile(photo))
        assertNotNull("the photo was not recognised at all", info)
        assertEquals(MediaKind.IMAGE, info!!.kind)
        assertEquals(640, info.width)
        assertEquals(360, info.height)
        assertTrue("a still needs a length to sit on the timeline", info.durationUs > 0)
    }

    @Test
    fun importingAPhotoPutsItOnTheTimelineAndDecodesAFrame() {
        onMain { engine.addMedia(listOf(Uri.fromFile(photo))) }

        waitFor("the clip to arrive") { engine.state.value.project.clips.size == 1 }
        waitFor("a frame to be decoded") { engine.scene.value.front != null }

        val layer = engine.scene.value.front!!
        assertTrue(
            "a photo should be drawn from a decoded still, not the video texture",
            layer.source is LayerSource.Still,
        )
    }

    /**
     * The bug this app was first reported with, asserted at the far end: the
     * value has to arrive at the layer the renderer is handed, not merely at
     * the slider.
     */
    @Test
    fun movingAParameterReachesTheDrawnLayer() {
        onMain { engine.addMedia(listOf(Uri.fromFile(photo))) }
        waitFor("a frame to be decoded") { engine.scene.value.front != null }

        assertEquals(0f, engine.scene.value.front!!.adjustments.saturation, 1e-6f)

        onMain {
            engine.beginChange()
            engine.setAdjustment(Adjustments.SATURATION, -1f)
        }

        waitFor("the drawn layer to pick the change up") {
            engine.scene.value.front?.adjustments?.saturation == -1f
        }
    }

    /** Nothing was selected, and the edit still has to land. */
    @Test
    fun anEditAppliesWithoutTappingTheClipFirst() {
        onMain { engine.addMedia(listOf(Uri.fromFile(photo))) }
        waitFor("the clip to arrive") { engine.state.value.project.clips.size == 1 }
        onMain { engine.selectClip(null) }

        onMain { engine.setAdjustment(Adjustments.EXPOSURE, 1.5f) }
        waitFor("the exposure to reach the clip") {
            engine.state.value.project.clips.first().adjustments.exposure == 1.5f
        }
    }

    @Test
    fun theCanvasStaysPortraitWhenALandscapePhotoIsImported() {
        onMain { engine.addMedia(listOf(Uri.fromFile(photo))) }
        waitFor("the clip to arrive") { engine.state.value.project.clips.size == 1 }

        assertEquals(
            "a landscape import turned the canvas sideways",
            com.delicat.studio.model.CanvasRatio.PORTRAIT_9_16,
            engine.state.value.project.aspect,
        )
    }
}
