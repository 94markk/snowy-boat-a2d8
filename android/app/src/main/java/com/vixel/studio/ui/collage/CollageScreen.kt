package com.vixel.studio.ui.collage

import android.graphics.Bitmap
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Slider
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshots.SnapshotStateList
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.vixel.studio.core.io.ImageFormat
import com.vixel.studio.core.io.ImageIo
import com.vixel.studio.core.model.AspectRatio
import com.vixel.studio.engine.photo.CollageLayout
import com.vixel.studio.engine.photo.CollageLayouts
import com.vixel.studio.engine.photo.CollageRenderer
import com.vixel.studio.ui.common.Chip
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

@Composable
fun CollageScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val snackbar = remember { SnackbarHostState() }

    val photos: SnapshotStateList<Bitmap> = remember { mutableStateListOf() }
    var layout by remember { mutableStateOf(CollageLayouts.ALL.first()) }
    var aspect by remember { mutableStateOf(AspectRatio.SQUARE) }
    var spacing by remember { mutableStateOf(0.015f) }
    var radius by remember { mutableStateOf(0.02f) }
    var white by remember { mutableStateOf(true) }
    var preview by remember { mutableStateOf<Bitmap?>(null) }
    var busy by remember { mutableStateOf(false) }

    val picker = rememberLauncherForActivityResult(
        ActivityResultContracts.PickMultipleVisualMedia(9),
    ) { uris ->
        if (uris.isEmpty()) return@rememberLauncherForActivityResult
        scope.launch {
            busy = true
            val loaded = withContext(Dispatchers.IO) {
                uris.mapNotNull { ImageIo.decode(context, it, maxEdge = 1600) }
            }
            photos.forEach { if (!it.isRecycled) it.recycle() }
            photos.clear()
            photos.addAll(loaded)
            layout = CollageLayouts.forCount(loaded.size).first()
            busy = false
        }
    }

    // Re-render the preview whenever anything that affects it changes.
    LaunchedEffect(photos.size, layout, aspect, spacing, radius, white) {
        if (photos.isEmpty()) {
            preview = null
            return@LaunchedEffect
        }
        busy = true
        val snapshot = photos.toList()
        val result = withContext(Dispatchers.Default) {
            runCatching {
                CollageRenderer.render(
                    bitmaps = snapshot,
                    layout = layout,
                    options = CollageRenderer.Options(
                        aspect = aspect,
                        // Preview only; Save re-renders at full size.
                        size = 1080,
                        spacing = spacing,
                        cornerRadius = radius,
                        backgroundColor = if (white) android.graphics.Color.WHITE
                        else android.graphics.Color.BLACK,
                    ),
                )
            }.getOrNull()
        }
        preview = result
        busy = false
    }

    Scaffold(snackbarHost = { SnackbarHost(snackbar) }) { inner ->
        Column(modifier = Modifier.fillMaxSize().padding(inner)) {
            Row(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                IconButton(onClick = onBack) {
                    Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back")
                }
                Text(
                    "Collage",
                    style = MaterialTheme.typography.titleMedium,
                    modifier = Modifier.weight(1f),
                )
            }

            Box(
                modifier = Modifier
                    .weight(1f)
                    .fillMaxWidth()
                    .background(MaterialTheme.colorScheme.background),
                contentAlignment = Alignment.Center,
            ) {
                val shown = preview
                if (shown == null && !busy) {
                    Column(
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.spacedBy(12.dp),
                        modifier = Modifier.padding(32.dp),
                    ) {
                        Text("No photos yet", style = MaterialTheme.typography.titleMedium)
                        Text(
                            "Pick up to 9 photos. Each one is centre-cropped to its cell, " +
                                "never squashed.",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            textAlign = TextAlign.Center,
                        )
                        Button(
                            onClick = {
                                picker.launch(
                                    PickVisualMediaRequest(
                                        ActivityResultContracts.PickVisualMedia.ImageOnly,
                                    ),
                                )
                            },
                        ) { Text("Pick photos") }
                    }
                } else if (shown != null) {
                    Image(
                        bitmap = shown.asImageBitmap(),
                        contentDescription = "Collage preview",
                        contentScale = ContentScale.Fit,
                        modifier = Modifier.fillMaxSize().padding(12.dp),
                    )
                }
                if (busy) CircularProgressIndicator(color = MaterialTheme.colorScheme.primary)
            }

            if (photos.isNotEmpty()) {
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .verticalScroll(rememberScrollState())
                        .padding(12.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Text("Layout", style = MaterialTheme.typography.labelMedium)
                    Row(
                        modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        CollageLayouts.forCount(photos.size).forEach { option: CollageLayout ->
                            Chip(
                                label = option.label,
                                selected = option.id == layout.id,
                                onClick = { layout = option },
                            )
                        }
                    }

                    Text("Shape", style = MaterialTheme.typography.labelMedium)
                    Row(
                        modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        AspectRatio.entries.forEach { entry ->
                            Chip(
                                label = entry.label,
                                selected = entry == aspect,
                                onClick = { aspect = entry },
                            )
                        }
                    }

                    SliderRow("Spacing", spacing, 0f, 0.08f) { spacing = it }
                    SliderRow("Corners", radius, 0f, 0.12f) { radius = it }

                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Chip("White", selected = white, onClick = { white = true })
                        Chip("Black", selected = !white, onClick = { white = false })
                        Chip(
                            "Change photos",
                            selected = false,
                            onClick = {
                                picker.launch(
                                    PickVisualMediaRequest(
                                        ActivityResultContracts.PickVisualMedia.ImageOnly,
                                    ),
                                )
                            },
                        )
                    }

                    Button(
                        enabled = !busy,
                        onClick = {
                            val snapshot = photos.toList()
                            val currentLayout = layout
                            scope.launch {
                                busy = true
                                val message = withContext(Dispatchers.Default) {
                                    runCatching {
                                        val full = CollageRenderer.render(
                                            bitmaps = snapshot,
                                            layout = currentLayout,
                                            options = CollageRenderer.Options(
                                                aspect = aspect,
                                                size = 2048,
                                                spacing = spacing,
                                                cornerRadius = radius,
                                                backgroundColor = if (white) {
                                                    android.graphics.Color.WHITE
                                                } else {
                                                    android.graphics.Color.BLACK
                                                },
                                            ),
                                        )
                                        ImageIo.saveToGallery(context, full, ImageFormat.JPEG)
                                        full.recycle()
                                    }.fold(
                                        onSuccess = { "Saved to Pictures/${ImageIo.ALBUM}" },
                                        onFailure = { "Save failed: ${it.message ?: "unknown"}" },
                                    )
                                }
                                busy = false
                                snackbar.showSnackbar(message)
                            }
                        },
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Text("Save collage")
                    }
                }
            }
        }
    }
}

@Composable
private fun SliderRow(
    label: String,
    value: Float,
    min: Float,
    max: Float,
    onChange: (Float) -> Unit,
) {
    Column {
        Row(modifier = Modifier.fillMaxWidth()) {
            Text(
                label,
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.weight(1f),
            )
            Text(
                "${((value - min) / (max - min) * 100).toInt()}",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.primary,
            )
        }
        Slider(value = value, onValueChange = onChange, valueRange = min..max)
    }
}
