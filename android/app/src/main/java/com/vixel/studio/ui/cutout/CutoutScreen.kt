package com.vixel.studio.ui.cutout

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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.vixel.studio.core.io.ImageFormat
import com.vixel.studio.core.io.ImageIo
import com.vixel.studio.engine.photo.BackgroundRemover
import com.vixel.studio.ui.common.Chip
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private enum class CutoutMode(val label: String) {
    AUTO("Auto subject"),
    CHROMA("Green screen"),
}

@Composable
fun CutoutScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val snackbar = remember { SnackbarHostState() }

    var source by remember { mutableStateOf<Bitmap?>(null) }
    var preview by remember { mutableStateOf<Bitmap?>(null) }
    var busy by remember { mutableStateOf(false) }
    var mode by remember { mutableStateOf(CutoutMode.AUTO) }

    var sensitivity by remember { mutableStateOf(0.5f) }
    var feather by remember { mutableStateOf(0.35f) }
    var tolerance by remember { mutableStateOf(0.35f) }
    var softness by remember { mutableStateOf(0.15f) }
    var spill by remember { mutableStateOf(0.6f) }

    val picker = rememberLauncherForActivityResult(
        ActivityResultContracts.PickVisualMedia(),
    ) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        scope.launch {
            busy = true
            // The matte is computed at a working resolution anyway, so a 1600px
            // source is plenty for an accurate on-screen preview.
            val bitmap = withContext(Dispatchers.IO) { ImageIo.decode(context, uri, maxEdge = 1600) }
            busy = false
            if (bitmap == null) snackbar.showSnackbar("Could not open that image") else source = bitmap
        }
    }

    // Recompute whenever the source or any parameter changes.
    LaunchedEffect(source, mode, sensitivity, feather, tolerance, softness, spill) {
        val input = source ?: return@LaunchedEffect
        busy = true
        val result = withContext(Dispatchers.Default) {
            runCatching {
                when (mode) {
                    CutoutMode.AUTO -> BackgroundRemover.removeBackground(
                        input,
                        BackgroundRemover.Options(sensitivity = sensitivity, feather = feather),
                    )
                    CutoutMode.CHROMA -> BackgroundRemover.chromaKey(
                        input,
                        BackgroundRemover.ChromaOptions(
                            tolerance = tolerance,
                            softness = softness,
                            spillRemoval = spill,
                        ),
                    )
                }
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
                    "Background remover",
                    style = MaterialTheme.typography.titleMedium,
                    modifier = Modifier.weight(1f),
                )
            }

            Box(
                modifier = Modifier
                    .weight(1f)
                    .fillMaxWidth()
                    .checkerboard(),
                contentAlignment = Alignment.Center,
            ) {
                val shown = preview ?: source
                if (shown == null) {
                    Column(
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.spacedBy(12.dp),
                        modifier = Modifier.padding(32.dp),
                    ) {
                        Text("No photo open", style = MaterialTheme.typography.titleMedium)
                        Text(
                            "Auto works best on a clear subject against a fairly plain " +
                                "background. Use Green screen for chroma footage.",
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
                        ) { Text("Open photo") }
                    }
                } else {
                    Image(
                        bitmap = shown.asImageBitmap(),
                        contentDescription = "Cutout preview",
                        contentScale = ContentScale.Fit,
                        modifier = Modifier.fillMaxSize().padding(8.dp),
                    )
                }
                if (busy) CircularProgressIndicator(color = MaterialTheme.colorScheme.primary)
            }

            if (source != null) {
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .verticalScroll(rememberScrollState())
                        .padding(12.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        CutoutMode.entries.forEach { entry ->
                            Chip(
                                label = entry.label,
                                selected = entry == mode,
                                onClick = { mode = entry },
                            )
                        }
                    }

                    if (mode == CutoutMode.AUTO) {
                        LabeledSlider("Sensitivity", sensitivity) { sensitivity = it }
                        LabeledSlider("Edge feather", feather) { feather = it }
                    } else {
                        LabeledSlider("Tolerance", tolerance) { tolerance = it }
                        LabeledSlider("Edge softness", softness) { softness = it }
                        LabeledSlider("Spill removal", spill) { spill = it }
                    }

                    Button(
                        enabled = !busy && preview != null,
                        onClick = {
                            val bitmap = preview ?: return@Button
                            scope.launch {
                                busy = true
                                val result = runCatching {
                                    withContext(Dispatchers.IO) {
                                        ImageIo.saveToGallery(
                                            context = context,
                                            bitmap = bitmap,
                                            // PNG: JPEG has no alpha, so a cutout
                                            // saved as JPEG loses its transparency.
                                            format = ImageFormat.PNG,
                                        )
                                    }
                                }
                                busy = false
                                snackbar.showSnackbar(
                                    if (result.isSuccess) {
                                        "Saved transparent PNG to Pictures/${ImageIo.ALBUM}"
                                    } else {
                                        "Save failed: ${result.exceptionOrNull()?.message ?: "unknown"}"
                                    },
                                )
                            }
                        },
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Text("Save transparent PNG")
                    }
                }
            }
        }
    }
}

@Composable
private fun LabeledSlider(label: String, value: Float, onChange: (Float) -> Unit) {
    Column {
        Row(modifier = Modifier.fillMaxWidth()) {
            Text(
                label,
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.weight(1f),
            )
            Text(
                "${(value * 100).toInt()}",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.primary,
            )
        }
        Slider(value = value, onValueChange = onChange, valueRange = 0f..1f)
    }
}

/** Standard transparency checkerboard so alpha is visible. */
private fun Modifier.checkerboard(): Modifier = this
    .background(Color(0xFF1A1A22))
    .drawBehind {
        val cell = 16.dp.toPx()
        val cols = (size.width / cell).toInt() + 1
        val rows = (size.height / cell).toInt() + 1
        for (row in 0 until rows) {
            for (col in 0 until cols) {
                if ((row + col) % 2 == 0) continue
                drawRect(
                    color = Color(0xFF23232E),
                    topLeft = Offset(col * cell, row * cell),
                    size = Size(cell, cell),
                )
            }
        }
    }
