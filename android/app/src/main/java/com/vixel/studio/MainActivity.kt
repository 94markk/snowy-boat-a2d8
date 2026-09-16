package com.vixel.studio

import android.content.pm.ActivityInfo
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import com.vixel.studio.core.Settings
import com.vixel.studio.ui.VixelNavHost
import com.vixel.studio.ui.theme.VixelTheme

class MainActivity : ComponentActivity() {

    private lateinit var settings: Settings

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        settings = Settings(this)
        applyOrientation()
        enableEdgeToEdge()

        setContent {
            VixelTheme {
                VixelNavHost(
                    allowRotation = settings.allowRotation,
                    onAllowRotationChange = { allow ->
                        settings.allowRotation = allow
                        applyOrientation()
                    },
                )
            }
        }
    }

    override fun onResume() {
        super.onResume()
        // Re-assert on resume: some launchers and split-screen transitions hand
        // the activity back with a sensor-derived orientation.
        applyOrientation()
    }

    /**
     * The manifest pins `portrait`, which is what fixes the app opening
     * sideways. This only ever *widens* that to user-controlled rotation when
     * the person has explicitly asked for it in Settings.
     */
    private fun applyOrientation() {
        requestedOrientation = if (settings.allowRotation) {
            ActivityInfo.SCREEN_ORIENTATION_USER
        } else {
            ActivityInfo.SCREEN_ORIENTATION_PORTRAIT
        }
    }
}
