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
import androidx.compose.material.icons.rounded.MusicNote
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.SwapHoriz
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import com.vixel.studio.ui.Routes
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
    Tool("Audio", "Extract, trim, voiceover", Icons.Rounded.MusicNote, Routes.AUDIO, Violet),
    Tool("Convert", "Video to audio, formats", Icons.Rounded.SwapHoriz, Routes.CONVERT, Azure),
)

@Composable
fun HomeScreen(onOpenTool: (String) -> Unit) {
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
                NewProjectCard(onClick = { onOpenTool(Routes.VIDEO_EDITOR) })
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
