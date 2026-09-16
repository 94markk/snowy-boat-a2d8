package com.vixel.studio.ui.home

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AutoFixHigh
import androidx.compose.material.icons.rounded.GridView
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.Movie
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.SwapHoriz
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import com.vixel.studio.core.store.ProjectStore
import com.vixel.studio.core.store.ProjectSummary
import com.vixel.studio.ui.Routes
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import com.vixel.studio.ui.theme.Aqua
import com.vixel.studio.ui.theme.Azure
import com.vixel.studio.ui.theme.Violet

private data class Tool(
    val title: String,
    val subtitle: String,
    val icon: ImageVector,
    val route: String,
    val tint: Color,
)

private val tools = listOf(
    Tool("Video editor", "Timeline, effects, export", Icons.Rounded.Movie, Routes.VIDEO_EDITOR, Aqua),
    Tool("Photo editor", "Adjust, filter, retouch", Icons.Rounded.Image, Routes.PHOTO_EDITOR, Violet),
    Tool("Collage", "Layouts and grids", Icons.Rounded.GridView, Routes.COLLAGE, Azure),
    Tool("Cutout", "Remove background", Icons.Rounded.AutoFixHigh, Routes.CUTOUT, Aqua),
    Tool("Convert", "Video to audio, formats", Icons.Rounded.SwapHoriz, Routes.CONVERT, Violet),
)

@Composable
fun HomeScreen(onOpenTool: (String) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var projects by remember { mutableStateOf<List<ProjectSummary>>(emptyList()) }
    var reloadToken by remember { mutableStateOf(0) }

    // Re-read on every visit so a project saved in the editor shows up here.
    LaunchedEffect(reloadToken) {
        projects = withContext(Dispatchers.IO) { ProjectStore.list(context) }
    }

    Scaffold { inner ->
        LazyVerticalGrid(
            columns = GridCells.Fixed(2),
            modifier = Modifier.fillMaxSize().padding(inner),
            contentPadding = androidx.compose.foundation.layout.PaddingValues(
                start = 16.dp, end = 16.dp, top = 8.dp, bottom = 32.dp,
            ),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            item(span = { GridItemSpan(maxLineSpan) }) {
                Header(onSettings = { onOpenTool(Routes.SETTINGS) })
            }
            item(span = { GridItemSpan(maxLineSpan) }) {
                NewProjectCard(onClick = { onOpenTool(Routes.videoEditor(null)) })
            }

            if (projects.isNotEmpty()) {
                item(span = { GridItemSpan(maxLineSpan) }) {
                    Text(
                        "Your projects",
                        style = MaterialTheme.typography.titleMedium,
                        modifier = Modifier.padding(top = 12.dp, bottom = 2.dp),
                    )
                }
                items(projects, span = { GridItemSpan(maxLineSpan) }) { summary ->
                    ProjectRow(
                        summary = summary,
                        onOpen = { onOpenTool(Routes.videoEditor(summary.id)) },
                        onDelete = {
                            scope.launch {
                                withContext(Dispatchers.IO) {
                                    ProjectStore.delete(context, summary.id)
                                }
                                reloadToken++
                            }
                        },
                    )
                }
            }
            item(span = { GridItemSpan(maxLineSpan) }) {
                Text(
                    "Tools",
                    style = MaterialTheme.typography.titleMedium,
                    modifier = Modifier.padding(top = 12.dp, bottom = 2.dp),
                )
            }
            items(tools) { tool -> ToolCard(tool, onClick = { onOpenTool(tool.route) }) }
        }
    }
}

@Composable
private fun Header(onSettings: () -> Unit) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Text("Vixel Studio", style = MaterialTheme.typography.titleLarge)
            Text(
                "Video and photo, one place",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        IconButton(onClick = onSettings) {
            Icon(Icons.Rounded.Settings, contentDescription = "Settings")
        }
    }
}

@Composable
private fun NewProjectCard(onClick: () -> Unit) {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(132.dp)
            .clip(RoundedCornerShape(20.dp))
            .background(
                Brush.linearGradient(listOf(Aqua.copy(alpha = 0.22f), Violet.copy(alpha = 0.18f)))
            )
            .border(1.dp, MaterialTheme.colorScheme.outlineVariant, RoundedCornerShape(20.dp))
            .clickable(onClick = onClick),
        contentAlignment = Alignment.CenterStart,
    ) {
        Column(modifier = Modifier.padding(20.dp)) {
            Text("New project", style = MaterialTheme.typography.titleLarge)
            Text(
                "Start in 9:16 — portrait first",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun ProjectRow(
    summary: ProjectSummary,
    onOpen: () -> Unit,
    onDelete: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(14.dp))
            .background(MaterialTheme.colorScheme.surface)
            .border(1.dp, MaterialTheme.colorScheme.outlineVariant, RoundedCornerShape(14.dp))
            .clickable(onClick = onOpen)
            .padding(start = 14.dp, top = 10.dp, bottom = 10.dp, end = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Text(
                summary.name.ifBlank { "Untitled" },
                style = MaterialTheme.typography.titleMedium,
            )
            Text(
                buildString {
                    append(summary.aspectId)
                    append("  ·  ")
                    append("${summary.clipCount} clip${if (summary.clipCount == 1) "" else "s"}")
                    if (summary.overlayCount > 0) {
                        append("  ·  ")
                        append("${summary.overlayCount} overlay${if (summary.overlayCount == 1) "" else "s"}")
                    }
                    append("  ·  ")
                    append(formatDuration(summary.durationUs))
                },
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        IconButton(onClick = onDelete) {
            Icon(
                Icons.Rounded.Delete,
                contentDescription = "Delete project",
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

private fun formatDuration(us: Long): String {
    val totalSeconds = us / 1_000_000
    val minutes = totalSeconds / 60
    val seconds = totalSeconds % 60
    return "%d:%02d".format(minutes, seconds)
}

@Composable
private fun ToolCard(tool: Tool, onClick: () -> Unit) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .aspectRatio(1.05f)
            .clip(RoundedCornerShape(18.dp))
            .background(MaterialTheme.colorScheme.surface)
            .border(1.dp, MaterialTheme.colorScheme.outlineVariant, RoundedCornerShape(18.dp))
            .clickable(onClick = onClick)
            .padding(16.dp),
        verticalArrangement = Arrangement.SpaceBetween,
    ) {
        Box(
            modifier = Modifier
                .size(40.dp)
                .clip(RoundedCornerShape(12.dp))
                .background(tool.tint.copy(alpha = 0.16f)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(tool.icon, contentDescription = null, tint = tool.tint)
        }
        Column {
            Text(tool.title, style = MaterialTheme.typography.titleMedium)
            Text(
                tool.subtitle,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}
