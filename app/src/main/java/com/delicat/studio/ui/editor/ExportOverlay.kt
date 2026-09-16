package com.delicat.studio.ui.editor

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import com.delicat.studio.engine.EditorEngine
import com.delicat.studio.engine.EditorState
import com.delicat.studio.engine.export.ExportState
import com.delicat.studio.ui.theme.Accent
import com.delicat.studio.ui.theme.Danger
import com.delicat.studio.ui.theme.Glyphs
import com.delicat.studio.ui.theme.Ink
import com.delicat.studio.ui.theme.Palette

/**
 * What the app says while it is writing the file, and after.
 *
 * Deliberately blocking. Export holds a GL context, two codecs and a muxer;
 * letting the timeline be edited underneath it would mean rendering a project
 * that no longer exists.
 */
@Composable
fun ExportOverlay(state: EditorState, editor: EditorEngine) {
    val export = state.export
    if (export is ExportState.Idle) return

    val context = LocalContext.current

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Ink.Black.copy(alpha = 0.86f))
            // Swallows taps so nothing behind the sheet can be touched.
            .clickable(enabled = true, onClick = {}),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier
                .padding(28.dp)
                .clip(RoundedCornerShape(18.dp))
                .background(Ink.Panel)
                .padding(24.dp),
        ) {
            when (export) {
                is ExportState.Running -> {
                    Text(
                        export.stage,
                        style = MaterialTheme.typography.titleMedium,
                        color = Palette.Primary,
                    )
                    Text(
                        "${(export.fraction * 100).toInt()}%",
                        style = MaterialTheme.typography.bodySmall,
                        color = Accent,
                        modifier = Modifier.padding(top = 4.dp),
                    )
                    LinearProgressIndicator(
                        progress = { export.fraction.coerceIn(0f, 1f) },
                        color = Accent,
                        trackColor = Ink.Chip,
                        modifier = Modifier
                            .padding(top = 16.dp)
                            .fillMaxWidth()
                            .height(4.dp),
                    )
                    TextAction("Cancel", Danger, editor::cancelExport)
                }

                is ExportState.Done -> {
                    Icon(
                        Glyphs.Check,
                        contentDescription = null,
                        tint = Accent,
                        modifier = Modifier.size(34.dp),
                    )
                    Text(
                        "Saved to your gallery",
                        style = MaterialTheme.typography.titleMedium,
                        color = Palette.Primary,
                        modifier = Modifier.padding(top = 12.dp),
                    )
                    Text(
                        "Movies / Delicat Studio / ${export.displayName}",
                        style = MaterialTheme.typography.labelSmall,
                        color = Palette.Faint,
                        modifier = Modifier.padding(top = 4.dp),
                    )
                    Row(
                        horizontalArrangement = Arrangement.spacedBy(6.dp),
                        modifier = Modifier.padding(top = 10.dp),
                    ) {
                        TextAction("Play", Accent) {
                            runCatching {
                                context.startActivity(
                                    Intent(Intent.ACTION_VIEW).apply {
                                        setDataAndType(Uri.parse(export.uri), "video/mp4")
                                        addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                                    },
                                )
                            }
                        }
                        TextAction("Share", Accent) {
                            runCatching {
                                context.startActivity(
                                    Intent.createChooser(
                                        Intent(Intent.ACTION_SEND).apply {
                                            type = "video/mp4"
                                            putExtra(Intent.EXTRA_STREAM, Uri.parse(export.uri))
                                            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                                        },
                                        "Share video",
                                    ),
                                )
                            }
                        }
                        TextAction("Done", Palette.Secondary, editor::dismissExport)
                    }
                }

                is ExportState.Failed -> {
                    Text(
                        "The export stopped",
                        style = MaterialTheme.typography.titleMedium,
                        color = Palette.Primary,
                    )
                    Text(
                        export.reason,
                        style = MaterialTheme.typography.bodySmall,
                        color = Palette.Secondary,
                        modifier = Modifier.padding(top = 8.dp),
                    )
                    TextAction("Close", Accent, editor::dismissExport)
                }

                ExportState.Idle -> Unit
            }
        }
    }
}

@Composable
private fun TextAction(
    label: String,
    colour: androidx.compose.ui.graphics.Color,
    onClick: () -> Unit,
) {
    Text(
        label,
        style = MaterialTheme.typography.labelLarge,
        color = colour,
        modifier = Modifier
            .padding(top = 12.dp)
            .clip(RoundedCornerShape(50))
            .clickable(onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 8.dp),
    )
}
