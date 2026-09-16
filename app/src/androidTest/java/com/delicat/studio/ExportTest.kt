package com.delicat.studio

import android.media.MediaExtractor
import android.media.MediaFormat
import android.media.MediaMetadataRetriever
import android.net.Uri
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.delicat.studio.engine.MediaProbe
import com.delicat.studio.engine.export.ExportRequest
import com.delicat.studio.engine.export.ExportState
import com.delicat.studio.engine.export.Exporter
import com.delicat.studio.model.Adjustments
import com.delicat.studio.model.CanvasRatio
import com.delicat.studio.model.Clip
import com.delicat.studio.model.MediaKind
import com.delicat.studio.model.Project
import com.delicat.studio.model.Transition
import com.delicat.studio.model.TransitionType
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import java.io.File
import kotlin.math.abs

/**
 * Exports a project and opens the result.
 *
 * Export has never been run before this. It is the one part of the app whose
 * output nobody sees until the very end, and the one where a mistake costs the
 * user the whole render rather than a redraw, so it is worth knowing that the
 * file exists, opens, and lasts as long as the timeline says it should.
 */
@RunWith(AndroidJUnit4::class)
class ExportTest {

    private val context = InstrumentationRegistry.getInstrumentation().targetContext
    private val rubbish = mutableListOf<File>()

    @Before
    fun setUp() = rubbish.clear()

    @After
    fun tearDown() {
        rubbish.forEach { it.delete() }
        File(context.cacheDir, "exports").listFiles()?.forEach { it.delete() }
    }

    private fun clipFrom(file: File): Clip {
        rubbish += file
        val info = MediaProbe.probe(context, Uri.fromFile(file))
            ?: throw AssertionError("the generated video would not open")
        assertEquals(MediaKind.VIDEO, info.kind)
        return Clip(
            uri = info.uri.toString(),
            kind = info.kind,
            sourceDurationUs = info.durationUs,
            trimEndUs = info.durationUs,
            sourceWidth = info.width,
            sourceHeight = info.height,
            sourceRotationDegrees = info.rotationDegrees,
        )
    }

    private fun export(project: Project): File {
        val result = runBlocking {
            Exporter(context).run(ExportRequest(project = project, shortEdge = 240, fps = 15)) { _, _ -> }
        }
        assertTrue(
            "the export did not finish: $result",
            result is ExportState.Done,
        )
        val uri = Uri.parse((result as ExportState.Done).uri)
        val file = if (uri.scheme == "file") {
            File(uri.path ?: throw AssertionError("no path in $uri"))
        } else {
            // Published into the gallery, so it has no path of its own. Copied
            // out to be inspected, which also proves it is readable back.
            File(context.cacheDir, "readback-${System.nanoTime()}.mp4").also { copy ->
                context.contentResolver.openInputStream(uri)
                    ?.use { input -> copy.outputStream().use { input.copyTo(it) } }
                    ?: throw AssertionError("the export could not be read back from $uri")
                context.contentResolver.delete(uri, null, null)
            }
        }
        rubbish += file
        assertTrue("the exported file is not there", file.exists())
        assertTrue("the exported file is empty", file.length() > 1024)
        return file
    }

    private fun durationUsOf(file: File): Long {
        val retriever = MediaMetadataRetriever()
        try {
            retriever.setDataSource(file.absolutePath)
            return (
                retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)
                    ?.toLongOrNull() ?: 0L
                ) * 1000L
        } finally {
            retriever.release()
        }
    }

    private fun tracksOf(file: File): List<String> {
        val extractor = MediaExtractor()
        return try {
            extractor.setDataSource(file.absolutePath)
            (0 until extractor.trackCount).mapNotNull {
                extractor.getTrackFormat(it).getString(MediaFormat.KEY_MIME)
            }
        } finally {
            extractor.release()
        }
    }

    @Test
    fun oneClipExportsToAPlayableFile() {
        val project = Project(
            aspect = CanvasRatio.PORTRAIT_9_16,
            clips = listOf(clipFrom(TestMedia.writeVideo(context, seconds = 1f))),
        )
        val file = export(project)

        assertTrue("no video track in the export", tracksOf(file).any { it.startsWith("video/") })
        val drift = abs(durationUsOf(file) - project.durationUs)
        assertTrue(
            "the export lasts ${durationUsOf(file)}us, the timeline says ${project.durationUs}us",
            drift < 350_000L,
        )
    }

    /**
     * Grading has to survive the trip. The colour is not checked here — the
     * pipeline test does that against the table — only that the export runs
     * the full path rather than quietly skipping it.
     */
    @Test
    fun aGradedClipStillExports() {
        val base = clipFrom(TestMedia.writeVideo(context, seconds = 1f))
        val project = Project(
            clips = listOf(
                base.copy(
                    adjustments = Adjustments(
                        exposure = 0.5f,
                        saturation = -0.4f,
                        vignette = 0.6f,
                        grain = 0.3f,
                        lookId = "blockbuster",
                    ),
                ),
            ),
        )
        val file = export(project)
        assertTrue(tracksOf(file).any { it.startsWith("video/") })
    }

    @Test
    fun twoClipsJoinedByATransitionExportAsOne() {
        val first = clipFrom(TestMedia.writeVideo(context, seconds = 1f, colour = 0xFF3C78D8.toInt()))
        val second = clipFrom(TestMedia.writeVideo(context, seconds = 1f, colour = 0xFFD86A3C.toInt()))
            .copy(transition = Transition(TransitionType.DISSOLVE, 400_000L))

        val project = Project(clips = listOf(first, second))
        val file = export(project)

        // The overlap has to come off the total, or a transition silently
        // makes the finished video longer than the timeline it came from.
        val drift = abs(durationUsOf(file) - project.durationUs)
        assertTrue(
            "the export lasts ${durationUsOf(file)}us against a timeline of ${project.durationUs}us",
            drift < 350_000L,
        )
        assertTrue(
            "the transition did not shorten the timeline",
            project.durationUs < first.timelineDurationUs + second.timelineDurationUs,
        )
    }

    @Test
    fun anEmptyTimelineFailsInsteadOfWritingRubbish() {
        val result = runBlocking {
            Exporter(context).run(ExportRequest(project = Project())) { _, _ -> }
        }
        assertTrue("an empty project should refuse: $result", result is ExportState.Failed)
    }
}
