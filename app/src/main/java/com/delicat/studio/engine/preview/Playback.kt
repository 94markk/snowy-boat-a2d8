package com.delicat.studio.engine.preview

import android.content.Context
import android.net.Uri
import android.view.Surface
import androidx.media3.common.MediaItem
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.common.VideoSize
import androidx.media3.exoplayer.ExoPlayer
import com.delicat.studio.model.Clip
import com.delicat.studio.model.MediaKind

/**
 * The single video player behind the preview.
 *
 * One clip is loaded at a time rather than the whole timeline as a playlist.
 * A playlist looks tempting because it plays across cuts by itself, but it
 * also means the player owns the running order, and an editor has to be able
 * to reorder, split and retime clips without the thing playing them deciding
 * when that takes effect. Here the timeline is authoritative and the player
 * only ever answers "where are you inside this one clip".
 *
 * [prepare] is guarded by an identity string. The bug this is guarding
 * against has already been written once: state flows into it on every edit,
 * and without the guard a brightness slider re-prepares the player sixty
 * times a second and playback snaps back to the start of the clip.
 */
class Playback(context: Context) {

    val player: ExoPlayer = ExoPlayer.Builder(context.applicationContext).build()

    private var loadedKey: String? = null
    private var attachedSurface: Surface? = null
    private var recoveries = 0

    /** Size the player reports, which is authoritative once a frame arrives. */
    var videoWidth: Int = 0
        private set
    var videoHeight: Int = 0
        private set

    /** Rotation the decoder did not apply and the renderer therefore must. */
    var pendingRotation: Int = 0
        private set

    var onError: ((String) -> Unit)? = null
    var onReady: (() -> Unit)? = null

    init {
        player.addListener(object : Player.Listener {
            override fun onVideoSizeChanged(size: VideoSize) {
                videoWidth = size.width
                videoHeight = size.height
                pendingRotation = size.unappliedRotationDegrees
            }

            override fun onPlayerError(error: PlaybackException) {
                // A decoder can fail to start for reasons that pass: another
                // app holding the only instance of a codec, or a surface that
                // was not ready yet. One more attempt costs a moment and
                // fixes the transient cases; saying so after every one of
                // them would bury the permanent ones in noise.
                if (attachedSurface != null && recoveries < MAX_RECOVERIES) {
                    recoveries++
                    player.prepare()
                    return
                }
                onError?.invoke(error.errorCodeName)
            }

            override fun onPlaybackStateChanged(state: Int) {
                if (state == Player.STATE_READY) onReady?.invoke()
            }
        })
    }

    /**
     * Points the player at somewhere to draw.
     *
     * If it already gave up — typically because it was asked to start a
     * decoder before this arrived — the arrival of a live surface is exactly
     * the condition that makes another attempt worth making, and preparing
     * again is the documented way to take it.
     */
    fun attach(surface: Surface) {
        attachedSurface = surface
        player.setVideoSurface(surface)
        if (player.playerError != null) {
            recoveries = 0
            player.prepare()
        }
    }

    fun detach() {
        player.setVideoSurface(null)
        attachedSurface = null
    }

    /** True when [prepare] would have to start a decoder for this clip. */
    fun needsDecoderFor(clip: Clip): Boolean =
        clip.kind == MediaKind.VIDEO && keyOf(clip) != loadedKey

    /** Identity of what is loaded. Anything not in it can change without a reload. */
    private fun keyOf(clip: Clip): String =
        "${clip.uri}|${clip.trimStartUs}|${clip.trimEndUs}"

    /**
     * Loads [clip] if it is not already loaded, and returns true if it was.
     *
     * Speed and volume are deliberately outside the key: both can be applied
     * to a playing item, and reloading for them would make the speed slider
     * stutter for exactly the reason the guard exists.
     */
    fun prepare(clip: Clip, playWhenReady: Boolean): Boolean {
        if (clip.kind != MediaKind.VIDEO) {
            if (loadedKey != null) {
                player.stop()
                player.clearMediaItems()
                loadedKey = null
            }
            return false
        }

        val key = keyOf(clip)
        val changed = key != loadedKey
        if (changed) {
            val item = MediaItem.Builder()
                .setUri(Uri.parse(clip.uri))
                .setClippingConfiguration(
                    MediaItem.ClippingConfiguration.Builder()
                        .setStartPositionMs(clip.trimStartUs / 1000)
                        .setEndPositionMs(clip.trimEndUs / 1000)
                        .build(),
                )
                .build()
            player.setMediaItem(item)
            player.prepare()
            loadedKey = key
            videoWidth = 0
            videoHeight = 0
            recoveries = 0
        }

        applyTrack(clip)
        player.playWhenReady = playWhenReady
        return changed
    }

    /** Volume and speed, which apply without reloading. */
    fun applyTrack(clip: Clip) {
        val speed = clip.speed.coerceIn(0.1f, 8f)
        if (player.playbackParameters.speed != speed) player.setPlaybackSpeed(speed)
        val volume = if (clip.muted) 0f else clip.volume.coerceIn(0f, 2f)
        if (player.volume != volume) player.volume = volume
    }

    /** Position inside the clip, from its trimmed start, in microseconds. */
    fun positionUs(): Long = player.currentPosition.coerceAtLeast(0L) * 1000L

    fun seekWithin(clip: Clip, intoUs: Long) {
        val limit = clip.trimmedDurationUs.coerceAtLeast(0L)
        player.seekTo((intoUs.coerceIn(0L, limit)) / 1000L)
    }

    fun setPlaying(playing: Boolean) {
        player.playWhenReady = playing
    }

    val isBuffering: Boolean
        get() = player.playbackState == Player.STATE_BUFFERING

    private companion object {
        /** Enough to ride out a codec that is briefly busy, not enough to loop. */
        const val MAX_RECOVERIES = 2
    }

    fun release() {
        detach()
        player.release()
        loadedKey = null
    }
}
