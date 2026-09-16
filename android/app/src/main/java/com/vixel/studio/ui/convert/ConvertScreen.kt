package com.vixel.studio.ui.convert

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Slider
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import com.vixel.studio.core.io.ImageFormat
import com.vixel.studio.core.io.ImageIo
import com.vixel.studio.engine.audio.AudioExport
import com.vixel.studio.engine.audio.AudioFormat
import com.vixel.studio.ui.common.Chip
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Standalone conversions that do not need a timeline: pulling audio out of a
 * video, and re-encoding a still between formats.
 */
@Composable
fun ConvertScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val snackbar = remember { SnackbarHostState() }
    var busy by remember { mutableStateOf(false) }

    var audioFormat by remember { mutableStateOf(AudioFormat.M4A) }
    var imageFormat by remember { mutableStateOf(ImageFormat.PNG) }
    var quality by remember { mutableStateOf(95f) }

    val videoPicker = rememberLauncherForActivityResult(
        ActivityResultContracts.PickVisualMedia(),
    ) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        scope.launch {
            busy = true
            val message = withContext(Dispatchers.IO) {
                val output = AudioExport.newFile(context, audioFormat)
                val ok = when (audioFormat) {
                    AudioFormat.M4A -> AudioExport.extractToM4a(context, uri, output)
                    AudioFormat.WAV -> AudioExport.exportWav(context, uri, output)
                }
                if (!ok) {
                    if (audioFormat == AudioFormat.M4A) {
                        "No re-muxable audio track found. Try WAV, which always works."
                    } else {
                        "That file has no audio track"
                    }
                } else {
                    runCatching { AudioExport.publishToGallery(context, output, audioFormat) }
                        .fold(
                            onSuccess = { "Saved to Music/${AudioExport.ALBUM}" },
                            onFailure = { "Saved to ${output.absolutePath}" },
                        )
                }
            }
            busy = false
            snackbar.showSnackbar(message)
        }
    }

    val imagePicker = rememberLauncherForActivityResult(
        ActivityResultContracts.PickVisualMedia(),
    ) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        scope.launch {
            busy = true
            val message = withContext(Dispatchers.IO) {
                val bitmap = ImageIo.decode(context, uri)
                if (bitmap == null) {
                    "Could not open that image"
                } else {
                    val result = runCatching {
                        ImageIo.saveToGallery(
                            context = context,
                            bitmap = bitmap,
                            format = imageFormat,
                            quality = quality.toInt(),
                        )
                    }
                    bitmap.recycle()
                    result.fold(
                        onSuccess = { "Saved ${imageFormat.label} to Pictures/${ImageIo.ALBUM}" },
                        onFailure = { "Convert failed: ${it.message ?: "unknown error"}" },
                    )
                }
            }
            busy = false
            snackbar.showSnackbar(message)
        }
    }

    Scaffold(snackbarHost = { SnackbarHost(snackbar) }) { inner ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(inner)
                .verticalScroll(rememberScrollState()),
        ) {
            Row(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                IconButton(onClick = onBack) {
                    Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back")
                }
                Text(
                    "Converter",
                    style = MaterialTheme.typography.titleMedium,
                    modifier = Modifier.weight(1f),
                )
            }

            if (busy) {
                LinearProgressIndicator(modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp))
            }

            Column(
                modifier = Modifier.padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Text("Video to audio", style = MaterialTheme.typography.titleMedium)
                Text(
                    "M4A copies the existing track with no re-encode, so it is instant " +
                        "and lossless. WAV decodes to uncompressed 44.1 kHz stereo.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    AudioFormat.entries.forEach { entry ->
                        Chip(
                            label = entry.extension.uppercase(),
                            selected = entry == audioFormat,
                            onClick = { audioFormat = entry },
                        )
                    }
                }
                Button(
                    enabled = !busy,
                    onClick = {
                        videoPicker.launch(
                            PickVisualMediaRequest(
                                ActivityResultContracts.PickVisualMedia.VideoOnly,
                            ),
                        )
                    },
                    modifier = Modifier.fillMaxWidth(),
                ) { Text("Pick a video") }
            }

            HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)

            Column(
                modifier = Modifier.padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Text("Image format", style = MaterialTheme.typography.titleMedium)
                Text(
                    "PNG and WebP keep transparency; JPEG does not.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ImageFormat.entries.forEach { entry ->
                        Chip(
                            label = entry.label,
                            selected = entry == imageFormat,
                            onClick = { imageFormat = entry },
                        )
                    }
                }
                if (imageFormat != ImageFormat.PNG) {
                    Text(
                        "Quality ${quality.toInt()}",
                        style = MaterialTheme.typography.labelMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Slider(
                        value = quality,
                        onValueChange = { quality = it },
                        valueRange = 50f..100f,
                    )
                }
                Button(
                    enabled = !busy,
                    onClick = {
                        imagePicker.launch(
                            PickVisualMediaRequest(
                                ActivityResultContracts.PickVisualMedia.ImageOnly,
                            ),
                        )
                    },
                    modifier = Modifier.fillMaxWidth(),
                ) { Text("Pick an image") }
            }
        }
    }
}
