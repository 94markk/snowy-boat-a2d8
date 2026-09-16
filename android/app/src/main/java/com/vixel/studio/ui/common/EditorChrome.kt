package com.vixel.studio.ui.common

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
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
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.vixel.studio.ui.theme.Aqua
import com.vixel.studio.ui.theme.Ink700
import com.vixel.studio.ui.theme.Ink800
import com.vixel.studio.ui.theme.Mist200
import com.vixel.studio.ui.theme.Mist400

/** Anything that can appear on a [ToolRail]. */
interface RailItem {
    val label: String
    val icon: ImageVector
}

/**
 * The bottom rail of tools, scrolled horizontally.
 *
 * A rail rather than tabs because tabs divide the width between them: past
 * about five, every one is too narrow to label or to hit. A rail keeps each
 * item at a fixed, tappable size and lets the list grow past what fits.
 */
@Composable
fun ToolRail(
    tools: List<RailItem>,
    onSelect: (RailItem) -> Unit,
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
            Column(
                modifier = Modifier
                    .clip(RoundedCornerShape(10.dp))
                    .clickable { onSelect(tool) }
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
    }
}

/**
 * A tool's controls, opened over the timeline with the canvas left visible.
 *
 * Keeping the image on screen is the whole point: a colour or text change
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

/** Hairline between the stacked regions of an editor. */
@Composable
fun ChromeDivider() {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(1.dp)
            .background(Ink700),
    )
}
