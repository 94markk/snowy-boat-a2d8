package com.delicat.studio

import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * The editor has to survive its own preview starting up.
 *
 * The renderer's surface is created on the GL thread and handed to a player
 * that accepts calls from the main thread only. Handing it across with a
 * coroutine launch looked right and was not: whether it changed threads at all
 * depended on the interceptor in force, and under one of them it ran inline on
 * the GL thread, threw, and took the editor down before a single frame. This
 * is here so that never passes silently again.
 */
@RunWith(AndroidJUnit4::class)
class PreviewThreadingTest {

    @get:Rule
    val compose = createAndroidComposeRule<MainActivity>()

    @Test
    fun theEditorSurvivesThePreviewStarting() {
        // Long enough for the GL thread to come up, create its surface and
        // call back into the player.
        compose.waitForIdle()
        Thread.sleep(1_500)
        compose.waitForIdle()

        // Reaching here at all means no exception escaped the GL thread, and
        // the activity is still the one that was started.
        assert(!compose.activity.isFinishing) { "the editor finished itself while starting up" }
    }
}
