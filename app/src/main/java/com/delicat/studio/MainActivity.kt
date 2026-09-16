package com.delicat.studio

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
