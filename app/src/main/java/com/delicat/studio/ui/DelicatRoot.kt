package com.delicat.studio.ui

import androidx.compose.runtime.Composable
import com.delicat.studio.ui.editor.EditorScreen

/**
 * The whole app is one screen.
 *
 * There is no navigation library and no back stack: an editor has exactly one
 * place the work happens, and routing machinery around a single destination is
 * weight with nothing to carry. Closing the editor closes the app, which is
 * what the system back gesture already means.
 */
@Composable
fun DelicatRoot(onExit: () -> Unit) {
    EditorScreen(onClose = onExit)
}
