package com.vixel.studio.ui.photo

import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.horizontalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Crop
import androidx.compose.material.icons.rounded.EmojiEmotions
import androidx.compose.material.icons.rounded.FileDownload
import androidx.compose.material.icons.rounded.PhotoFilter
import androidx.compose.material.icons.rounded.TextFields
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.Compare
import androidx.compose.material.icons.rounded.Redo
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.draw.clip
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.vixel.studio.core.io.ImageFormat
import com.vixel.studio.core.io.ImageIo
import com.vixel.studio.core.model.AdjustSpec
import com.vixel.studio.core.model.Adjustments
import com.vixel.studio.core.model.FilterPreset
import com.vixel.studio.core.model.Filters
import com.vixel.studio.engine.photo.PhotoEngine
import com.vixel.studio.ui.common.Chip
import com.vixel.studio.ui.common.ChromeDivider
import com.vixel.studio.ui.common.RailItem
import com.vixel.studio.ui.common.ToolRail
import com.vixel.studio.ui.common.ToolSheet
import com.vixel.studio.ui.common.ParamSlider
import com.vixel.studio.ui.common.PhotoPreview
import com.vixel.studio.ui.video.StickerPanel
import com.vixel.studio.ui.video.TextPanel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** The tools on the photo editor's bottom rail. */
private enum class Tab(
    override val label: String,
    override val icon: ImageVector,
) : RailItem {
    ADJUST("Adjust", Icons.Rounded.Tune),
    FILTERS("Filters", Icons.Rounded.PhotoFilter),
    TEXT("Text", Icons.Rounded.TextFields),
    STICKERS("Stickers", Icons.Rounded.EmojiEmotions),
    TRANSFORM("Transform", Icons.Rounded.Crop),
    EXPORT("Export", Icons.Rounded.FileDownload),
}

@Composable
fun PhotoEditorScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val state = remember { PhotoEditorState() }
    val snackbar = remember { SnackbarHostState() }

    var tab by remember { mutableStateOf<Tab?>(null) }
    var group by remember { mutableStateOf(AdjustSpec.Group.LIGHT) }

    val picker = rememberLauncherForActivityResult(
        ActivityResultContracts.PickVisualMedia(),
    ) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        scope.launch {
            state.busy = true
            val bitmap = withContext(Dispatchers.IO) { ImageIo.decode(context, uri) }
            state.busy = false
            if (bitmap == null) {
                snackbar.showSnackbar("Could not open that image")
            } else {
                state.load(bitmap)
            }
        }
    }

    fun pick() = picker.launch(
        PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly),
    )

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
    ) { inner ->
        Column(modifier = Modifier.fillMaxSize().padding(inner)) {

            TopBar(
                state = state,
                onBack = onBack,
                onPick = { pick() },
            )

            Box(
                modifier = Modifier
                    .weight(1f)
                    .fillMaxWidth()
                    .background(MaterialTheme.colorScheme.background),
                contentAlignment = Alignment.Center,
            ) {
                if (state.source == null) {
                    EmptyState(onPick = { pick() })
                } else {
                    PhotoPreview(
                        bitmap = state.source,
                        adjustments = state.effective,
                        overlays = if (state.showOriginal) emptyList() else state.overlays,
                        modifier = Modifier.fillMaxSize(),
                    )
                }
                if (state.busy) {
                    CircularProgressIndicator(color = MaterialTheme.colorScheme.primary)
                }
            }

            if (state.source != null) {
                ChromeDivider()
                val current = tab
                if (current == null) {
                    ToolRail(tools = Tab.entries, onSelect = { tab = it as Tab })
                } else {
                    ToolSheet(title = current.label, onBack = { tab = null }) {
                        when (current) {
                            Tab.ADJUST -> AdjustPanel(state, group, onGroupChange = { group = it })
                            Tab.FILTERS -> FilterPanel(state)
                            Tab.TEXT -> TextPanel(state)
                            Tab.STICKERS -> StickerPanel(state)
                            Tab.TRANSFORM -> TransformPanel(state, scope)
                            Tab.EXPORT -> ExportPanel(state) { message ->
                                scope.launch { snackbar.showSnackbar(message) }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun TopBar(
    state: PhotoEditorState,
    onBack: () -> Unit,
    onPick: () -> Unit,
) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconButton(onClick = onBack) {
            Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back")
        }
        Text(
            "Photo",
            style = MaterialTheme.typography.titleMedium,
            modifier = Modifier.weight(1f),
        )
        // Press and hold to see the untouched original.
        Box(
            modifier = Modifier
                .size(48.dp)
                .pointerInput(Unit) {
                    detectTapGestures(
                        onPress = {
                            state.showOriginal = true
                            tryAwaitRelease()
                            state.showOriginal = false
                        },
                    )
                },
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.Rounded.Compare,
                contentDescription = "Hold to compare with original",
                tint = if (state.showOriginal) {
                    MaterialTheme.colorScheme.primary
                } else {
                    MaterialTheme.colorScheme.onSurfaceVariant
                },
            )
        }
        IconButton(onClick = { state.undo() }, enabled = state.canUndo) {
            Icon(Icons.Rounded.Undo, contentDescription = "Undo")
        }
        IconButton(onClick = { state.redo() }, enabled = state.canRedo) {
            Icon(Icons.Rounded.Redo, contentDescription = "Redo")
        }
        IconButton(onClick = onPick) {
            Icon(Icons.Rounded.AddPhotoAlternate, contentDescription = "Open a photo")
        }
    }
}

@Composable
private fun EmptyState(onPick: () -> Unit) {
    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(12.dp),
        modifier = Modifier.padding(32.dp),
    ) {
        Text("No photo open", style = MaterialTheme.typography.titleMedium)
        Text(
            "Pick a photo to start editing. Every slider updates the preview live.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center,
        )
        Button(onClick = onPick) { Text("Open photo") }
    }
}

@Composable
private fun AdjustPanel(
    state: PhotoEditorState,
    group: AdjustSpec.Group,
    onGroupChange: (AdjustSpec.Group) -> Unit,
) {
    Column {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState())
                .padding(horizontal = 12.dp, vertical = 4.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            AdjustSpec.Group.entries.forEach { entry ->
                Chip(
                    label = entry.name.lowercase().replaceFirstChar { it.uppercase() },
                    selected = entry == group,
                    onClick = { onGroupChange(entry) },
                )
            }
        }
        LazyColumn(contentPadding = PaddingValues(bottom = 16.dp)) {
            items(AdjustSpec.group(group)) { spec ->
                ParamSlider(
                    spec = spec,
                    value = state.adjustments.get(spec.id),
                    onValueChange = { value ->
                        state.beginGesture()
                        state.update { it.set(spec.id, value) }
                    },
                    onValueChangeFinished = { state.endGesture() },
                    onReset = { state.commit { it.set(spec.id, spec.default) } },
                )
            }
        }
    }
}

@Composable
private fun FilterPanel(state: PhotoEditorState) {
    val current = state.adjustments.filterId
    Column {
        LazyRow(
            contentPadding = PaddingValues(horizontal = 12.dp, vertical = 8.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            items(Filters.ALL) { preset: FilterPreset ->
                Chip(
                    label = preset.name,
                    selected = preset.id == current,
                    onClick = { state.commit { it.copy(filterId = preset.id) } },
                )
            }
        }
        if (current != Filters.NONE_ID) {
            val spec = AdjustSpec(
                id = "filterStrength",
                label = "Strength",
                min = 0f,
                max = 1f,
                default = 1f,
                group = AdjustSpec.Group.EFFECT,
            )
            ParamSlider(
                spec = spec,
                value = state.adjustments.filterStrength,
                onValueChange = { value ->
                    state.beginGesture()
                    state.update { it.copy(filterStrength = value) }
                },
                onValueChangeFinished = { state.endGesture() },
                onReset = { state.commit { it.copy(filterStrength = 1f) } },
            )
        }
        TextButton(
            onClick = { state.commit { it.resetColor() } },
            modifier = Modifier.padding(horizontal = 12.dp),
        ) {
            Text("Reset all adjustments")
        }
    }
}

@Composable
private fun TransformPanel(
    state: PhotoEditorState,
    scope: kotlinx.coroutines.CoroutineScope,
) {
    fun transform(block: (android.graphics.Bitmap) -> android.graphics.Bitmap) {
        val source = state.source ?: return
        scope.launch {
            state.busy = true
            val result = withContext(Dispatchers.IO) { block(source) }
            state.replaceSource(result)
            state.busy = false
        }
    }

    Column(
        modifier = Modifier
            .fillMaxWidth()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = { transform { ImageIo.rotate(it, 270f) } }) { Text("Rotate left") }
            Button(onClick = { transform { ImageIo.rotate(it, 90f) } }) { Text("Rotate right") }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = { transform { ImageIo.flip(it, horizontal = true) } }) { Text("Flip H") }
            Button(onClick = { transform { ImageIo.flip(it, horizontal = false) } }) { Text("Flip V") }
        }
        val source = state.source
        if (source != null) {
            Text(
                "${source.width} x ${source.height}",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun ExportPanel(state: PhotoEditorState, onMessage: (String) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var format by remember { mutableStateOf(ImageFormat.JPEG) }
    var quality by remember { mutableStateOf(95f) }

    Column(
        modifier = Modifier
            .fillMaxWidth()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            ImageFormat.entries.forEach { entry ->
                Chip(
                    label = entry.label,
                    selected = entry == format,
                    onClick = { format = entry },
                )
            }
        }

        if (format != ImageFormat.PNG) {
            Text(
                "Quality ${quality.toInt()}",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            androidx.compose.material3.Slider(
                value = quality,
                onValueChange = { quality = it },
                valueRange = 50f..100f,
            )
        }

        Button(
            onClick = {
                val source = state.source ?: return@Button
                scope.launch {
                    state.busy = true
                    val result = runCatching {
                        withContext(Dispatchers.IO) {
                            val rendered = PhotoEngine.render(
                                source, state.adjustments, state.overlays,
                            )
                            val uri = ImageIo.saveToGallery(
                                context = context,
                                bitmap = rendered,
                                format = format,
                                quality = quality.toInt(),
                            )
                            rendered.recycle()
                            uri
                        }
                    }
                    state.busy = false
                    result.fold(
                        onSuccess = { onMessage("Saved to Pictures/${ImageIo.ALBUM}") },
                        onFailure = { error ->
                            onMessage("Export failed: ${error.message ?: "unknown error"}")
                            Toast.makeText(context, "Export failed", Toast.LENGTH_SHORT).show()
                        },
                    )
                }
            },
            enabled = !state.busy,
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text("Save to gallery")
        }
    }
}
