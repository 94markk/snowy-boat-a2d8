package com.delicat.studio

import android.content.pm.ActivityInfo
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Starts the app the way a phone does.
 *
 * A Compose screen that throws while building is invisible to a compiler and
 * to every test of a pure function, and that is the shape of failure this
 * project has hit repeatedly. Launching it is the only way to know.
 */
@RunWith(AndroidJUnit4::class)
class EditorLaunchTest {

    @get:Rule
    val compose = createAndroidComposeRule<MainActivity>()

    @Test
    fun theEditorOpensOnAnEmptyTimeline() {
        compose.onNodeWithText("Nothing on the timeline yet").assertIsDisplayed()
        compose.onNodeWithText("Add photos or video").assertIsDisplayed()
    }

    @Test
    fun theToolsAreThere() {
        for (tool in listOf("Add", "Edit", "Adjust", "Looks", "Speed", "Volume", "Canvas")) {
            compose.onNodeWithContentDescription(tool).assertIsDisplayed()
        }
    }

    @Test
    fun theAppAsksToStayUpright() {
        assertEquals(
            ActivityInfo.SCREEN_ORIENTATION_PORTRAIT,
            compose.activity.requestedOrientation,
        )
    }
}
