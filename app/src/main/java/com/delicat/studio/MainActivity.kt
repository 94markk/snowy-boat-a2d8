package com.delicat.studio

import android.content.pm.ActivityInfo
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.core.view.WindowCompat
import com.delicat.studio.ui.DelicatRoot
import com.delicat.studio.ui.theme.DelicatTheme

class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)

        // The manifest already asks for portrait. This asks again at runtime,
        // because the two are overridden by different things: a per-app
        // setting or an OEM shell can outrank the manifest value and still
        // honour the request made here.
        requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_PORTRAIT

        // The editor draws its own dark chrome to the edges, so the system
        // bars are left transparent and the content decides what sits under
        // them. Light icons, because everything behind them is black.
        WindowCompat.getInsetsController(window, window.decorView)
            .isAppearanceLightStatusBars = false

        setContent {
            DelicatTheme {
                DelicatRoot(onExit = { finish() })
            }
        }
    }
}
