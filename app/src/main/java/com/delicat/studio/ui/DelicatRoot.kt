package com.delicat.studio.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddRounded
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.delicat.studio.ui.editor.EditorScreen
import com.delicat.studio.ui.theme.Accent
import com.delicat.studio.ui.theme.Ink
import com.delicat.studio.ui.theme.Text as Palette

/**
 * The whole app is one screen plus a way in.
 *
 * There is no navigation library and no back stack beyond this: an editor has
 * exactly one place the work happens, and routing machinery around a single
 * destination is weight with nothing to carry.
 */
@Composable
fun DelicatRoot() {
    var editing by remember { mutableStateOf(false) }

    if (editing) {
        EditorScreen(onClose = { editing = false })
    } else {
        Landing(onStart = { editing = true })
    }
}

@Composable
private fun Landing(onStart: () -> Unit) {
    Scaffold(containerColor = Ink.Black) { inner ->
        Box(
            modifier = Modifier
                .fillMaxSize()
                .padding(inner)
                .background(Ink.Black),
            contentAlignment = Alignment.Center,
        ) {
            Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                modifier = Modifier.fillMaxWidth().padding(horizontal = 32.dp),
            ) {
                Text(
                    "Delicat Studio",
                    style = MaterialTheme.typography.titleLarge,
                    color = Palette.Primary,
                )
                Text(
                    "Video and photo editing, on device",
                    style = MaterialTheme.typography.bodySmall,
                    color = Palette.Secondary,
                    modifier = Modifier.padding(top = 6.dp),
                )

                Button(
                    onClick = onStart,
                    colors = ButtonDefaults.buttonColors(
                        containerColor = Accent,
                        contentColor = Ink.Black,
                    ),
                    modifier = Modifier.padding(top = 28.dp),
                ) {
                    Icon(
                        Icons.Rounded.AddRounded,
                        contentDescription = null,
                        modifier = Modifier.size(18.dp),
                    )
                    Text(
                        "New project",
                        style = MaterialTheme.typography.labelLarge,
                        modifier = Modifier.padding(start = 8.dp),
                    )
                }
            }
        }
    }
}
