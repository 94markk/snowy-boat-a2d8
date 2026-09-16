package com.vixel.studio.ui.video

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import com.vixel.studio.core.model.AudioClip
import com.vixel.studio.core.store.ProjectStore
import com.vixel.studio.engine.audio.VoiceRecorder
import com.vixel.studio.engine.video.MediaProbe
import com.vixel.studio.ui.common.Chip
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Music and voiceover tracks.
 *
 * Music is picked with OpenDocument rather than the photo picker: the photo
 * picker does not offer audio, and a document uri can be granted long-lived
 * access so the track still resolves when the project is reopened.
 */
@Composable
fun AudioPanel(state: VideoEditorState, onMessage: (String) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    val recorder = remember { VoiceRecorder(context) }
    var recording by remember { mutableStateOf(false) }
    var selectedId by remember { mutableStateOf<String?>(null) }

    DisposableEffect(Unit) {
        onDispose { if (recorder.isRecording) recorder.cancel() }
    }

    val musicPicker = rememberLauncherForActivityResult(
        ActivityResultContracts.OpenDocument(),
    ) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        ProjectStore.tryPersistPermission(context, uri)
        scope.launch {
            val info = withContext(Dispatchers.IO) { MediaProbe.probe(context, uri) }
            if (info == null || info.durationUs <= 0L) {
                onMessage("That file has no readable audio")
                return@launch
            }
            val clip = AudioClip(
                uri = uri.toString(),
                title = info.title.ifBlank { "Music" },
                sourceDurationUs = info.durationUs,
                trimEndUs = info.durationUs,
                startOnTimelineUs = state.positionUs,
            )
            state.addAudio(clip)
            selectedId = clip.id
        }
    }

    val micPermission = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { granted ->
        if (!granted) {
            onMessage("Microphone access is needed to record a voiceover")
        } else if (recorder.start()) {
            recording = true
        } else {
            onMessage("Could not start recording")
        }
    }

    fun finishRecording() {
        val file = recorder.stop()
        recording = false
        if (file == null) {
            onMessage("Nothing was recorded")
            return
        }
        scope.launch {
            val uri = android.net.Uri.fromFile(file)
            val info = withContext(Dispatchers.IO) { MediaProbe.probe(context, uri) }
            val duration = info?.durationUs ?: 0L
            if (duration <= 0L) {
                onMessage("The recording was too short")
                file.delete()
                return@launch
            }
            val clip = AudioClip(
                uri = uri.toString(),
                title = "Voiceover",
                sourceDurationUs = duration,
                trimEndUs = duration,
                startOnTimelineUs = state.positionUs,
            )
            state.addAudio(clip)
            selectedId = clip.id
            onMessage("Voiceover added at the playhead")
        }
    }

    val tracks = state.project.audio
    val selected = tracks.firstOrNull { it.id == selectedId }

    LazyColumn(contentPadding = PaddingValues(bottom = 24.dp)) {
        item {
            Row(
                modifier = Modifier.fillMaxWidth().padding(16.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Button(onClick = { musicPicker.launch(arrayOf("audio/*")) }) { Text("Add music") }

                Button(
                    onClick = {
                        if (recording) {
                            finishRecording()
                        } else {
                            val granted = ContextCompat.checkSelfPermission(
                                context, Manifest.permission.RECORD_AUDIO,
                            ) == PackageManager.PERMISSION_GRANTED
                            if (granted) {
                                if (recorder.start()) recording = true
                                else onMessage("Could not start recording")
                            } else {
                                micPermission.launch(Manifest.permission.RECORD_AUDIO)
                            }
                        }
                    },
                ) {
                    Text(if (recording) "Stop recording" else "Record voiceover")
                }
            }
            if (recording) {
                Text(
                    "Recording. Tap stop when you are done; the clip lands at the playhead.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.primary,
                    modifier = Modifier.padding(horizontal = 16.dp),
                )
            }
        }

        if (tracks.isEmpty()) {
            item {
                Text(
                    "No audio tracks yet. Clip sound is controlled per clip on the Clip tab.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(16.dp),
                )
            }
            return@LazyColumn
        }

        item {
            SectionLabel("Tracks")
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .horizontalScroll(rememberScrollState())
                    .padding(horizontal = 16.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                tracks.forEach { track ->
                    Chip(
                        label = track.title.ifBlank { "Track" },
                        selected = track.id == selectedId,
                        onClick = { selectedId = track.id },
                    )
                }
            }
        }

        if (selected == null) return@LazyColumn

        item {
            Row(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                TextButton(
                    onClick = {
                        state.commit { it.copy(audio = it.audio.filterNot { a -> a.id == selected.id }) }
                        selectedId = null
                    },
                ) { Text("Remove track") }
            }
        }

        item {
            AudioTrackControls(state, selected)
        }
    }
}

@Composable
private fun AudioTrackControls(state: VideoEditorState, track: AudioClip) {
    val timelineEnd = state.project.durationUs.coerceAtLeast(1_000_000L)

    Column {
        OverlaySlider(
            label = "Volume",
            value = track.volume,
            range = 0f..2f,
            display = "${(track.volume * 100).toInt()}%",
            onChange = { v ->
                state.beginGesture()
                state.edit { it.updateAudio(track.id) { a -> a.copy(volume = v) } }
            },
            onFinished = { state.endGesture() },
        )
        OverlaySlider(
            label = "Starts at",
            value = track.startOnTimelineUs.toFloat(),
            range = 0f..timelineEnd.toFloat(),
            display = formatTimePrecise(track.startOnTimelineUs),
            onChange = { v ->
                state.beginGesture()
                state.edit { it.updateAudio(track.id) { a -> a.copy(startOnTimelineUs = v.toLong()) } }
            },
            onFinished = { state.endGesture() },
        )
        OverlaySlider(
            label = "Trim start",
            value = track.trimStartUs.toFloat(),
            range = 0f..track.sourceDurationUs.coerceAtLeast(1L).toFloat(),
            display = formatTimePrecise(track.trimStartUs),
            onChange = { v ->
                state.beginGesture()
                state.edit {
                    it.updateAudio(track.id) { a ->
                        val start = v.toLong().coerceAtMost(a.trimEndUs - MIN_OVERLAY_US)
                        a.copy(trimStartUs = start.coerceAtLeast(0L))
                    }
                }
            },
            onFinished = { state.endGesture() },
        )
        OverlaySlider(
            label = "Trim end",
            value = track.trimEndUs.toFloat(),
            range = MIN_OVERLAY_US.toFloat()..
                maxOf(track.sourceDurationUs, MIN_OVERLAY_US + 1L).toFloat(),
            display = formatTimePrecise(track.trimEndUs),
            onChange = { v ->
                state.beginGesture()
                state.edit {
                    it.updateAudio(track.id) { a ->
                        val end = v.toLong().coerceAtLeast(a.trimStartUs + MIN_OVERLAY_US)
                        a.copy(trimEndUs = end.coerceAtMost(a.sourceDurationUs))
                    }
                }
            },
            onFinished = { state.endGesture() },
        )
        OverlaySlider(
            label = "Fade in",
            value = track.fadeInUs.toFloat(),
            range = 0f..3_000_000f,
            display = formatTimePrecise(track.fadeInUs),
            onChange = { v ->
                state.beginGesture()
                state.edit { it.updateAudio(track.id) { a -> a.copy(fadeInUs = v.toLong()) } }
            },
            onFinished = { state.endGesture() },
        )
        OverlaySlider(
            label = "Fade out",
            value = track.fadeOutUs.toFloat(),
            range = 0f..3_000_000f,
            display = formatTimePrecise(track.fadeOutUs),
            onChange = { v ->
                state.beginGesture()
                state.edit { it.updateAudio(track.id) { a -> a.copy(fadeOutUs = v.toLong()) } }
            },
            onFinished = { state.endGesture() },
        )
    }
}
