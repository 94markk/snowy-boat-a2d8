package com.vixel.studio.ui

import androidx.compose.runtime.Composable
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import com.vixel.studio.ui.home.HomeScreen
import com.vixel.studio.ui.photo.PhotoEditorScreen
import com.vixel.studio.ui.convert.ConvertScreen
import com.vixel.studio.ui.cutout.CutoutScreen
import com.vixel.studio.ui.settings.SettingsScreen
import com.vixel.studio.ui.video.VideoEditorScreen

@Composable
fun VixelNavHost(
    allowRotation: Boolean,
    onAllowRotationChange: (Boolean) -> Unit,
) {
    val nav = rememberNavController()

    NavHost(navController = nav, startDestination = Routes.HOME) {
        composable(Routes.HOME) {
            HomeScreen(
                onOpenTool = { route -> nav.navigate(route) },
            )
        }
        composable(Routes.SETTINGS) {
            SettingsScreen(
                allowRotation = allowRotation,
                onAllowRotationChange = onAllowRotationChange,
                onBack = { nav.popBackStack() },
            )
        }
        composable(Routes.VIDEO_EDITOR) { VideoEditorScreen(onBack = { nav.popBackStack() }) }
        composable(Routes.PHOTO_EDITOR) { PhotoEditorScreen(onBack = { nav.popBackStack() }) }
        composable(Routes.COLLAGE) { ToolPlaceholder("Collage") { nav.popBackStack() } }
        composable(Routes.CUTOUT) { CutoutScreen(onBack = { nav.popBackStack() }) }
        composable(Routes.AUDIO) { ToolPlaceholder("Audio studio") { nav.popBackStack() } }
        composable(Routes.CONVERT) { ConvertScreen(onBack = { nav.popBackStack() }) }
    }
}
