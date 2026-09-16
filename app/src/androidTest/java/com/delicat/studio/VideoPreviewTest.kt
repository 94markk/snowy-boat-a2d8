package com.delicat.studio

import android.content.Context
import android.graphics.SurfaceTexture
import android.view.Surface
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.delicat.studio.engine.export.EglCore
import com.delicat.studio.engine.gl.GlUtil
import com.delicat.studio.engine.preview.Playback
import com.delicat.studio.model.Clip
import com.delicat.studio.model.MediaKind
import org.junit.After
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import java.io.File
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit

/**
 * Plays a video into the kind of surface the preview actually uses.
 *
 * This path had never been run anywhere. The device tests covered photos,
 * which never reach a decoder, and export drives its own decoder against its
 * own surface — so the one combination the app shows on launch, a player
 * writing into a SurfaceTexture owned by the preview's GL context, was the
 * only part with no coverage at all. It is also the part that failed on a real
 * phone with ERROR_CODE_DECODER_INIT_FAILED.
 *
 * The two tests differ by one line, on purpose. A SurfaceTexture made on its
 * own starts with a zero-sized buffer, and whether a decoder will accept a
 * surface in that state is a question about the device, not about the code —
 * so both are asked here and the answers are the diagnosis.
 */
@RunWith(AndroidJUnit4::class)
class VideoPreviewTest {

    private val context: Context = InstrumentationRegistry.getInstrumentation().targetContext
    private val rubbish = mutableListOf<File>()

    @After
    fun tearDown() = rubbish.forEach { it.delete() }

    private fun onMain(block: () -> Unit) =
        InstrumentationRegistry.getInstrumentation().runOnMainSync(block)

    /** Everything the preview holds, minus the drawing. */
    private class FakePreview(declareBufferSize: Boolean) {
        val egl = EglCore()
        val eglSurface = egl.createOffscreenSurface(16, 16)
        val textureId: Int
        val surfaceTexture: SurfaceTexture
        val surface: Surface

        init {
            egl.makeCurrent(eglSurface)
            textureId = GlUtil.createTexture(GlUtil.EXTERNAL_TEXTURE)
            surfaceTexture = SurfaceTexture(textureId)
            if (declareBufferSize) surfaceTexture.setDefaultBufferSize(1280, 720)
            surface = Surface(surfaceTexture)
        }

        fun release() {
            surface.release()
            surfaceTexture.release()
            GlUtil.deleteTexture(textureId)
            egl.releaseSurface(eglSurface)
            egl.release()
        }
    }

    /** Returns the error the player reported, or null if it got as far as ready. */
    private fun playInto(declareBufferSize: Boolean): String? {
        val file = TestMedia.writeVideo(context, width = 640, height = 480, seconds = 1f)
        rubbish += file

        val preview = FakePreview(declareBufferSize)
        var playback: Playback? = null
        val settled = CountDownLatch(1)
        var failure: String? = null

        try {
            onMain {
                playback = Playback(context).also { player ->
                    player.onError = { reason ->
                        failure = reason
                        settled.countDown()
                    }
                    player.onReady = { settled.countDown() }
                    player.attach(preview.surface)
                    player.prepare(
                        Clip(
                            uri = android.net.Uri.fromFile(file).toString(),
                            kind = MediaKind.VIDEO,
                            sourceDurationUs = 1_000_000L,
                            trimEndUs = 1_000_000L,
                            sourceWidth = 640,
                            sourceHeight = 480,
                        ),
                        playWhenReady = false,
                    )
                }
            }

            assertTrue(
                "the player neither became ready nor reported a problem",
                settled.await(20, TimeUnit.SECONDS),
            )
            return failure
        } finally {
            onMain { playback?.release() }
            preview.release()
        }
    }

    /**
     * The state the app actually ships: a SurfaceTexture with nothing said
     * about its size. If a decoder refuses this, the preview is black on
     * launch and there is nothing wrong with any of the drawing code.
     */
    @Test
    fun aDecoderAcceptsASurfaceWithNoDeclaredSize() {
        val failure = playInto(declareBufferSize = false)
        assertNull("the decoder refused an unsized surface: $failure", failure)
    }

    /** The same surface, one line different, as a control. */
    @Test
    fun aDecoderAcceptsASurfaceWithADeclaredSize() {
        val failure = playInto(declareBufferSize = true)
        assertNull("the decoder refused even a sized surface: $failure", failure)
    }
}
