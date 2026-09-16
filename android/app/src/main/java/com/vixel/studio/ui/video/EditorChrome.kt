package com.vixel.studio.ui.video

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.AspectRatio
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.ClosedCaption
import androidx.compose.material.icons.rounded.ContentCut
import androidx.compose.material.icons.rounded.EmojiEmotions
import androidx.compose.material.icons.rounded.Fullscreen
import androidx.compose.material.icons.rounded.MusicNote
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.PhotoFilter
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Redo
import androidx.compose.material.icons.rounded.TextFields
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.vixel.studio.ui.theme.Aqua
import com.vixel.studio.ui.theme.Ink500
import com.vixel.studio.ui.theme.Ink700
import com.vixel.studio.ui.theme.Ink800
import com.vixel.studio.ui.theme.Mist200
import com.vixel.studio.ui.theme.Mist400
import com.vixel.studio.ui.theme.Mist600

/**
 * The tools on the bottom rail.
 *
 * One flat list, scrolled horizontally, rather than a row of tabs. Tabs have
 * to shrink to fit the screen, so nine of them arrive as unreadable slivers;
 * a rail keeps every item at a tappable size and lets the list grow.
 */
enum class EditorTool(val label: String, val icon: ImageVector) {
    EDIT("Edit", Icons.Rounded.ContentCut),
    AUDIO("Audio", Icons.Rounded.MusicNote),
    TEXT("Text", Icons.Rounded.TextFields),
    CAPTIONS("Captions", Icons.Rounded.ClosedCaption),
    STICKERS("Stickers", Icons.Rounded.EmojiEmotions),
    EFFECTS("Effects", Icons.Rounded.AutoAwesome),
    FILTERS("Filters", Icons.Rounded.PhotoFilter),
    ADJUST("Adjust", Icons.Rounded.Tune),
    CANVAS("Canvas", Icons.Rounded.AspectRatio),
}

@Composable
fun EditorTopBar(
    resolutionLabel: String,
    exportEnabled: Boolean,
    onClose: () -> Unit,
    onResolution: () -> Unit,
    onExport: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .background(Color.Black)
            .padding(horizontal = 6.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconButton(onClick = onClose) {
            Icon(Icons.Rounded.Close, contentDescription = "Close", tint = Mist200)
        }

        Spacer(Modifier.weight(1f))

        Row(
            modifier = Modifier
                .clip(RoundedCornerShape(8.dp))
                .background(Ink500)
                .clickable(onClick = onResolution)
                .padding(horizontal = 12.dp, vertical = 7.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                resolutionLabel,
                style = MaterialTheme.typography.labelMedium,
                color = Mist200,
            )
        }

        Spacer(Modifier.width(8.dp))

        Row(
            modifier = Modifier
                .clip(RoundedCornerShape(8.dp))
                .background(if (exportEnabled) Aqua else Ink500)
                .clickable(enabled = exportEnabled, onClick = onExport)
                .padding(horizontal = 16.dp, vertical = 7.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                "Export",
                style = MaterialTheme.typography.labelMedium.copy(fontWeight = FontWeight.Bold),
                color = if (exportEnabled) Color.Black else Mist600,
            )
        }
    }
}

/**
 * Play control plus the two actions that have to be reachable at all times.
 *
 * Undo sits here, not behind a menu: it is the control a user reaches for
 * after a change they did not intend, and burying it is what makes an editor
 * feel unsafe to experiment in.
 */
@Composable
fun TransportRow(
    isPlaying: Boolean,
    canUndo: Boolean,
    canRedo: Boolean,
    onPlayPause: () -> Unit,
    onUndo: () -> Unit,
    onRedo: () -> Unit,
    onFullscreen: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .background(Color.Black)
            .padding(horizontal = 10.dp, vertical = 2.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconButton(onClick = onFullscreen, modifier = Modifier.size(38.dp)) {
            Icon(
                Icons.Rounded.Fullscreen,
                contentDescription = "Fullscreen preview",
                tint = Mist400,
                modifier = Modifier.size(20.dp),
            )
        }

        Spacer(Modifier.weight(1f))

        IconButton(onClick = onPlayPause, modifier = Modifier.size(42.dp)) {
            Icon(
                if (isPlaying) Icons.Rounded.Pause else Icons.Rounded.PlayArrow,
                contentDescription = if (isPlaying) "Pause" else "Play",
                tint = Mist200,
                modifier = Modifier.size(28.dp),
            )
        }

        Spacer(Modifier.weight(1f))

        IconButton(
            onClick = onUndo,
            enabled = canUndo,
            modifier = Modifier.size(38.dp),
        ) {
            Icon(
                Icons.Rounded.Undo,
                contentDescription = "Undo",
                tint = Mist400,
                modifier = Modifier.size(20.dp).alpha(if (canUndo) 1f else 0.35f),
            )
        }
        IconButton(
            onClick = onRedo,
            enabled = canRedo,
            modifier = Modifier.size(38.dp),
        ) {
            Icon(
                Icons.Rounded.Redo,
                contentDescription = "Redo",
                tint = Mist400,
                modifier = Modifier.size(20.dp).alpha(if (canRedo) 1f else 0.35f),
            )
        }
    }
}

@Composable
fun ToolRail(
    tools: List<EditorTool>,
    onSelect: (EditorTool) -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier
            .fillMaxWidth()
            .background(Color.Black)
            .horizontalScroll(rememberScrollState())
            .padding(horizontal = 6.dp, vertical = 8.dp),
        horizontalArrangement = Arrangement.spacedBy(2.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        tools.forEach { tool ->
            ToolRailItem(tool = tool, onClick = { onSelect(tool) })
        }
    }
}

@Composable
private fun ToolRailItem(tool: EditorTool, onClick: () -> Unit) {
    Column(
        modifier = Modifier
            .clip(RoundedCornerShape(10.dp))
            .clickable(onClick = onClick)
            .width(64.dp)
            .padding(vertical = 6.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(5.dp),
    ) {
        Icon(
            tool.icon,
            contentDescription = null,
            tint = Mist200,
            modifier = Modifier.size(22.dp),
        )
        Text(
            tool.label,
            style = MaterialTheme.typography.labelSmall,
            color = Mist400,
            maxLines = 1,
        )
    }
}

/**
 * A tool's controls, opened over the timeline with the preview left visible.
 *
 * Keeping the frame on screen is the whole point: a colour or text change
 * judged against a hidden canvas has to be applied, dismissed, inspected and
 * reopened for every nudge.
 */
@Composable
fun ToolSheet(
    title: String,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    content: @Composable () -> Unit,
) {
    Column(
        modifier = modifier
            .fillMaxWidth()
            .background(Ink800),
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .background(Ink700)
                .padding(horizontal = 4.dp, vertical = 2.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            IconButton(onClick = onBack, modifier = Modifier.size(40.dp)) {
                Icon(
                    Icons.AutoMirrored.Rounded.ArrowBack,
                    contentDescription = "Back to tools",
                    tint = Mist200,
                    modifier = Modifier.size(20.dp),
                )
            }
            Text(
                title,
                style = MaterialTheme.typography.labelMedium,
                color = Mist200,
                modifier = Modifier.weight(1f),
            )
            Text(
                "Done",
                style = MaterialTheme.typography.labelMedium.copy(fontWeight = FontWeight.SemiBold),
                color = Aqua,
                modifier = Modifier
                    .clip(RoundedCornerShape(8.dp))
                    .clickable(onClick = onBack)
                    .padding(horizontal = 14.dp, vertical = 8.dp),
            )
        }

        Box(modifier = Modifier.fillMaxWidth().heightIn(min = 150.dp, max = 330.dp)) {
            content()
        }
    }
}

/** Thin divider used between the stacked regions of the editor. */
@Composable
fun ChromeDivider() {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(1.dp)
            .background(Ink700),
    )
}
