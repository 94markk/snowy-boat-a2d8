package com.vixel.studio.ui.video

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import com.vixel.studio.core.model.OverlayAnimation
import com.vixel.studio.core.model.OverlayTransform
import com.vixel.studio.core.model.TextAlignment
import com.vixel.studio.core.model.TextOverlay
import com.vixel.studio.core.model.TextStyle
import com.vixel.studio.engine.audio.CaptionGenerator
import com.vixel.studio.engine.audio.CaptionResult
import com.vixel.studio.ui.common.Chip
import kotlinx.coroutines.launch

/** How generated captions look. Readable over any footage, out of the way. */
private val captionStyle = TextStyle(
    bold = true,
    sizeFraction = 0.042f,
    color = 0xFFFFFFFF.toInt(),
    alignment = TextAlignment.CENTER,
    // An outline plus a plate keeps captions legible over bright and busy
    // footage alike, which a plain white caption is not.
    strokeWidth = 0.035f,
    strokeColor = 0xFF000000.toInt(),
    backgroundColor = 0x99000000.toInt(),
    backgroundPadding = 0.3f,
    backgroundRadius = 0.2f,
)

/** Captions sit low, clear of faces and of most platform UI. */
private const val CAPTION_Y = 0.82f

@Composable
fun CaptionControls(state: VideoEditorState, onMessage: (String) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var progress by remember { mutableStateOf<Float?>(null) }

    // SpeechRecognizer enforces RECORD_AUDIO even when the audio comes from a
    // descriptor rather than the microphone. Without this the request fails
    // with a permissions error and every utterance comes back empty, which
    // would read as "no speech found".
    val micPermission = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { granted ->
        if (!granted) {
            onMessage("Captions need microphone permission, even though nothing is recorded")
        }
    }

    val existing = state.project.overlays.filterIsInstance<TextOverlay>()
        .count { it.id.startsWith(CAPTION_PREFIX) }

    Column(modifier = Modifier.fillMaxWidth()) {
        SectionLabel("Auto captions")
        Text(
            "Transcribes speech on the device. Nothing is uploaded, and no " +
                "network connection is used.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.padding(horizontal = 16.dp),
        )

        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Chip(
                label = if (progress != null) "Transcribing..." else "Generate captions",
                selected = progress != null,
                onClick = {
                    if (progress != null) return@Chip
                    if (state.project.clips.isEmpty()) {
                        onMessage("Add a clip first")
                        return@Chip
                    }
                    val granted = ContextCompat.checkSelfPermission(
                        context, Manifest.permission.RECORD_AUDIO,
                    ) == PackageManager.PERMISSION_GRANTED
                    if (!granted) {
                        micPermission.launch(Manifest.permission.RECORD_AUDIO)
                        return@Chip
                    }
                    progress = 0f
                    val project = state.project

                    scope.launch {
                        val result = CaptionGenerator.generate(context, project) { p ->
                            progress = p
                        }
                        progress = null

                        when (result) {
                            is CaptionResult.Success -> {
                                val overlays = result.cues.mapIndexed { index, cue ->
                                    TextOverlay(
                                        id = "$CAPTION_PREFIX$index-${System.currentTimeMillis()}",
                                        text = cue.text,
                                        style = captionStyle,
                                        startUs = cue.startUs,
                                        endUs = cue.endUs,
                                        transform = OverlayTransform(x = 0.5f, y = CAPTION_Y),
                                        animationIn = OverlayAnimation.NONE,
                                        animationOut = OverlayAnimation.NONE,
                                    )
                                }
                                state.commit { it.copy(overlays = it.overlays + overlays) }
                                onMessage("Added ${overlays.size} captions")
                            }
                            is CaptionResult.Unsupported -> onMessage(result.reason)
                            is CaptionResult.Failure -> onMessage(result.message)
                        }
                    }
                },
            )

            if (existing > 0) {
                TextButton(
                    onClick = {
                        state.commit { project ->
                            project.copy(
                                overlays = project.overlays.filterNot {
                                    it.id.startsWith(CAPTION_PREFIX)
                                },
                            )
                        }
                        onMessage("Captions removed")
                    },
                ) { Text("Remove captions") }
            }
        }

        progress?.let { value ->
            LinearProgressIndicator(
                progress = { value },
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp),
            )
            Text(
                "This runs faster than real time, but a long timeline still takes a while.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 2.dp),
            )
        }

        if (existing > 0 && progress == null) {
            Text(
                "$existing caption${if (existing == 1) "" else "s"} on the timeline. " +
                    "Each one is an ordinary text overlay, so you can edit or restyle it.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 2.dp),
            )
        }
    }
}

/** Marks generated captions so they can be cleared as a group. */
private const val CAPTION_PREFIX = "caption-"
