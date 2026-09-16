package com.delicat.studio.ui.editor

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.weight
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CloseRounded
import androidx.compose.material.icons.rounded.MovieCreationOutlined
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.delicat.studio.ui.theme.Ink
import com.delicat.studio.ui.theme.Text as Palette

/**
 * The timeline editor.
 *
 * Laid out the way phone editors are: preview on top, transport under it, the
 * timeline below that, and a scrolling rail of tools along the bottom. Opening
 * a tool covers the timeline but never the preview, so the frame being graded
 * stays on screen while it is being graded.
 */
@Composable
fun EditorScreen(onClose: () -> Unit) {
    Scaffold(containerColor = Ink.Black) { inner ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(inner)
                .background(Ink.Black),
        ) {
            Row(
                modifier = Modifier.fillMaxWidth().padding(6.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                IconButton(onClick = onClose) {
                    Icon(
                        Icons.Rounded.CloseRounded,
                        contentDescription = "Close",
                        tint = Palette.Primary,
                    )
                }
            }

            Box(
                modifier = Modifier.weight(1f).fillMaxWidth(),
                contentAlignment = Alignment.Center,
            ) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(
                        Icons.Rounded.MovieCreationOutlined,
                        contentDescription = null,
                        tint = Palette.Faint,
                        modifier = Modifier.size(44.dp),
                    )
                    Text(
                        "Nothing on the timeline yet",
                        style = MaterialTheme.typography.bodyMedium,
                        color = Palette.Secondary,
                        modifier = Modifier.padding(top = 14.dp),
                    )
                }
            }
        }
    }
}
