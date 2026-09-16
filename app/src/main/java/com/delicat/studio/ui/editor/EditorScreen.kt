package com.delicat.studio.ui.editor

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBars
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import com.delicat.studio.engine.EditorEngine
import com.delicat.studio.engine.EditorState
import com.delicat.studio.engine.Tool
import com.delicat.studio.ui.common.ActionButton
import com.delicat.studio.ui.common.Sheet
import com.delicat.studio.ui.common.formatTime
import com.delicat.studio.ui.theme.Accent
import com.delicat.studio.ui.theme.Glyphs
import com.delicat.studio.ui.theme.Ink
import com.delicat.studio.ui.theme.Palette
import kotlinx.coroutines.delay

/**
 * The editor.
 *
 * Laid out the way phone editors are, and for the same reason: the preview
 * sits at the top where the eye rests, the timeline sits under the thumb, and
 * a tool opens between them. Opening a tool covers the timeline and never the
 * preview, so the frame being graded stays on screen while it is graded —
 * which is the entire point of grading it.
 */
@Composable
fun EditorScreen(onClose: () -> Unit) {
    val viewModel: EditorViewModel = viewModel()
    val editor = viewModel.editor
    val state by editor.state.collectAsStateWithLifecycle()
    val scene by editor.scene.collectAsStateWithLifecycle()

    val picker = rememberLauncherForActivityResult(
        ActivityResultContracts.PickMultipleVisualMedia(),
    ) { uris -> editor.addMedia(uris) }

    val openPicker: () -> Unit = {
        picker.launch(
            PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo),
        )
    }

    Box(modifier = Modifier.fillMaxSize().background(Ink.Black)) {
        Column(modifier = Modifier.fillMaxSize()) {
            TopBar(
                state = state,
                onClose = onClose,
                onUndo = editor::undo,
                onRedo = editor::redo,
                onExport = editor::export,
                modifier = Modifier.windowInsetsPadding(WindowInsets.statusBars),
            )

            Box(
                modifier = Modifier.weight(1f).fillMaxWidth().padding(horizontal = 6.dp),
                contentAlignment = Alignment.Center,
            ) {
                PreviewPane(
                    scene = scene,
                    onSurface = editor::attachSurface,
                    onError = editor::report,
                    modifier = Modifier.fillMaxSize(),
                )
                if (state.isEmpty) {
                    EmptyState(onAdd = openPicker, importing = state.isImporting)
                }
            }

            Transport(state = state, onToggle = editor::togglePlay)

            Timeline(
                state = state,
                frames = editor.frames,
                onScrub = editor::scrubTo,
                onSelect = editor::selectClip,
                onBeginChange = editor::beginChange,
                onTrim = editor::setTrim,
                onTransitionTap = { id ->
                    editor.selectClip(id)
                    editor.openTool(Tool.TRANSITION)
                },
                onZoom = editor::setZoom,
                onAdd = openPicker,
            )

            Box(modifier = Modifier.windowInsetsPadding(WindowInsets.navigationBars)) {
                val tool = state.tool
                if (tool == null) {
                    ToolRail(
                        enabled = !state.isEmpty,
                        onPick = editor::openTool,
                        onAdd = openPicker,
                    )
                } else {
                    ToolSheet(tool = tool, state = state, editor = editor)
                }
            }
        }

        Notice(state.notice, editor::clearNotice)
        ExportOverlay(state, editor)
    }
}

@Composable
private fun TopBar(
    state: EditorState,
    onClose: () -> Unit,
    onUndo: () -> Unit,
    onRedo: () -> Unit,
    onExport: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier.fillMaxWidth().padding(horizontal = 6.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconTap(Glyphs.Close, "Close", onClose)
        Spacer(Modifier.weight(1f))
        IconTap(Glyphs.Undo, "Undo", onUndo, enabled = state.canUndo)
        IconTap(Glyphs.Redo, "Redo", onRedo, enabled = state.canRedo)
        Spacer(Modifier.width(6.dp))
        ExportButton(enabled = !state.isEmpty, onClick = onExport)
    }
}

@Composable
private fun IconTap(
    icon: ImageVector,
    label: String,
    onClick: () -> Unit,
    enabled: Boolean = true,
) {
    Box(
        modifier = Modifier
            .size(40.dp)
            .clip(RoundedCornerShape(50))
            .clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(
            icon,
            contentDescription = label,
            tint = if (enabled) Palette.Primary else Palette.Faint,
            modifier = Modifier.size(20.dp),
        )
    }
}

@Composable
private fun ExportButton(enabled: Boolean, onClick: () -> Unit) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = Modifier
            .clip(RoundedCornerShape(50))
            .background(if (enabled) Accent else Ink.Raised)
            .clickable(enabled = enabled, onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 7.dp),
    ) {
        Icon(
            Glyphs.Export,
            contentDescription = null,
            tint = if (enabled) Ink.Black else Palette.Faint,
            modifier = Modifier.size(16.dp),
        )
        Text(
            "Export",
            style = MaterialTheme.typography.labelLarge,
            color = if (enabled) Ink.Black else Palette.Faint,
            modifier = Modifier.padding(start = 6.dp),
        )
    }
}

@Composable
private fun Transport(state: EditorState, onToggle: () -> Unit) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(horizontal = 18.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            formatTime(state.positionUs),
            style = MaterialTheme.typography.labelMedium,
            color = Accent,
        )
        Spacer(Modifier.weight(1f))
        Box(
            modifier = Modifier
                .size(40.dp)
                .clip(RoundedCornerShape(50))
                .background(Ink.Raised)
                .clickable(enabled = !state.isEmpty, onClick = onToggle),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                if (state.isPlaying) Glyphs.Pause else Glyphs.Play,
                contentDescription = if (state.isPlaying) "Pause" else "Play",
                tint = if (state.isEmpty) Palette.Faint else Palette.Primary,
                modifier = Modifier.size(22.dp),
            )
        }
        Spacer(Modifier.weight(1f))
        Text(
            formatTime(state.durationUs),
            style = MaterialTheme.typography.labelMedium,
            color = Palette.Faint,
        )
    }
}

@Composable
private fun ToolRail(enabled: Boolean, onPick: (Tool) -> Unit, onAdd: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .background(Ink.Near)
            .horizontalScroll(rememberScrollState())
            .padding(horizontal = 6.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        ActionButton(Glyphs.Add, "Add", onAdd, tint = Accent)
        ActionButton(Glyphs.Split, "Edit", { onPick(Tool.EDIT) }, enabled = enabled)
        ActionButton(Glyphs.Adjust, "Adjust", { onPick(Tool.ADJUST) }, enabled = enabled)
        ActionButton(Glyphs.Looks, "Looks", { onPick(Tool.LOOKS) }, enabled = enabled)
        ActionButton(Glyphs.Speed, "Speed", { onPick(Tool.SPEED) }, enabled = enabled)
        ActionButton(Glyphs.VolumeOn, "Volume", { onPick(Tool.VOLUME) }, enabled = enabled)
        ActionButton(
            Glyphs.Transition,
            "Transition",
            { onPick(Tool.TRANSITION) },
            enabled = enabled,
        )
        ActionButton(Glyphs.Canvas, "Canvas", { onPick(Tool.CANVAS) }, enabled = enabled)
    }
}

@Composable
private fun ToolSheet(tool: Tool, state: EditorState, editor: EditorEngine) {
    val clip = state.activeClip
    if (clip == null) {
        Sheet(title = tool.label, onClose = { editor.openTool(null) }) {
            Text(
                "Add something to the timeline first.",
                style = MaterialTheme.typography.bodySmall,
                color = Palette.Secondary,
                modifier = Modifier.padding(18.dp),
            )
        }
        return
    }

    Sheet(
        title = tool.label,
        onClose = { editor.openTool(null) },
        trailing = {
            if (tool == Tool.ADJUST || tool == Tool.LOOKS) {
                Box(
                    modifier = Modifier
                        .size(34.dp)
                        .clip(RoundedCornerShape(50))
                        .clickable(onClick = editor::resetAdjustments),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        Glyphs.Reset,
                        contentDescription = "Reset",
                        tint = Palette.Secondary,
                        modifier = Modifier.size(18.dp),
                    )
                }
            }
        },
    ) {
        when (tool) {
            Tool.EDIT -> EditPanel(
                clip = clip,
                canMoveEarlier = state.activeIndex > 0,
                canMoveLater = state.activeIndex in 0 until state.project.clips.lastIndex,
                onSplit = editor::split,
                onDuplicate = editor::duplicate,
                onDelete = editor::delete,
                onRotate = {
                    editor.beginChange()
                    editor.rotate()
                },
                onMove = { step ->
                    val from = state.activeIndex
                    editor.move(from, from + step)
                },
                onFit = { mode ->
                    editor.beginChange()
                    editor.setFit(mode)
                },
            )

            Tool.ADJUST -> AdjustPanel(
                clip = clip,
                onBegin = editor::beginChange,
                onChange = editor::setAdjustment,
            )

            Tool.LOOKS -> LooksPanel(
                clip = clip,
                onBegin = editor::beginChange,
                onPick = editor::setLook,
                onStrength = { value ->
                    editor.setAdjustment(
                        com.delicat.studio.model.Adjustments.LOOK_STRENGTH,
                        value,
                    )
                },
            )

            Tool.SPEED -> SpeedPanel(clip, editor::beginChange, editor::setSpeed)

            Tool.VOLUME -> VolumePanel(
                clip = clip,
                onBegin = editor::beginChange,
                onVolume = editor::setVolume,
                onMute = editor::toggleMute,
            )

            Tool.TRANSITION -> TransitionPanel(
                clip = clip,
                isFirst = state.activeIndex <= 0,
                onBegin = editor::beginChange,
                onPick = editor::setTransition,
            )

            Tool.CANVAS -> CanvasPanel(
                current = state.project.aspect,
                onPick = editor::setAspect,
                onMatchClip = editor::matchCanvasToClip,
            )
        }
    }
}

@Composable
private fun EmptyState(onAdd: () -> Unit, importing: Boolean) {
    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
        modifier = Modifier.fillMaxSize(),
    ) {
        Icon(
            Glyphs.Photo,
            contentDescription = null,
            tint = Palette.Faint,
            modifier = Modifier.size(42.dp),
        )
        Text(
            if (importing) "Reading your files" else "Nothing on the timeline yet",
            style = MaterialTheme.typography.bodyMedium,
            color = Palette.Secondary,
            modifier = Modifier.padding(top = 14.dp),
        )
        if (!importing) {
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier
                    .padding(top = 18.dp)
                    .clip(RoundedCornerShape(50))
                    .background(Accent)
                    .clickable(onClick = onAdd)
                    .padding(horizontal = 20.dp, vertical = 10.dp),
            ) {
                Icon(
                    Glyphs.Add,
                    contentDescription = null,
                    tint = Ink.Black,
                    modifier = Modifier.size(18.dp),
                )
                Text(
                    "Add photos or video",
                    style = MaterialTheme.typography.labelLarge,
                    color = Ink.Black,
                    modifier = Modifier.padding(start = 8.dp),
                )
            }
        }
    }
}

@Composable
private fun Notice(message: String?, onDismiss: () -> Unit) {
    // Cleared on a timer rather than by a button: a message about a file that
    // would not open is worth reading once and never worth dismissing.
    LaunchedEffect(message) {
        if (message != null) {
            delay(3_600L)
            onDismiss()
        }
    }

    Box(modifier = Modifier.fillMaxSize().systemBarsPadding(), contentAlignment = Alignment.TopCenter) {
        AnimatedVisibility(visible = message != null) {
            Text(
                message.orEmpty(),
                style = MaterialTheme.typography.bodySmall,
                color = Palette.Primary,
                modifier = Modifier
                    .padding(top = 56.dp)
                    .clip(RoundedCornerShape(10.dp))
                    .background(Ink.Raised)
                    .padding(horizontal = 14.dp, vertical = 9.dp),
            )
        }
    }
}
