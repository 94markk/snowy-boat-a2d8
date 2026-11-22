import 'dart:async';
import 'dart:io';

import 'package:flutter/scheduler.dart';
import 'package:material_ui/material_ui.dart';
import 'package:video_player/video_player.dart';

import 'video_model.dart';

/// Preview playback: a wall-clock master timeline drives one video player per
/// nearby clip (never more than a few, to stay light on budget phones).
class VideoPlayback extends ChangeNotifier {
  VideoPlayback(TickerProvider vsync) {
    _ticker = vsync.createTicker(_tick);
  }

  late final Ticker _ticker;
  final Stopwatch _clock = Stopwatch();
  double _startT = 0;

  VideoDoc doc = const VideoDoc();
  double t = 0;
  bool playing = false;
  int active = -1;

  final Map<String, VideoPlayerController> _ctl = {};
  final Map<String, Future<void>> _ready = {};
  VideoPlayerController? _music;
  String? _musicPath;

  /// One player per voice-over, created on demand.
  final Map<String, VideoPlayerController> _voices = {};
  final Set<String> _voicePlaying = {};

  /// Mutes every sound while a voice-over is being recorded (no echo into the mic).
  bool _silent = false;
  bool get silent => _silent;
  set silent(bool v) {
    _silent = v;
    for (final c in doc.clips) {
      controllerFor(c)?.setVolume(_volumeFor(c));
    }
    _music?.setVolume(v ? 0 : (doc.music?.volume ?? 0).clamp(0.0, 1.0));
    for (final e in _voices.entries) {
      final voice = doc.voices.where((x) => x.id == e.key).firstOrNull;
      e.value.setVolume(v ? 0 : (voice?.volume ?? 1).clamp(0.0, 1.0));
    }
  }

  bool _seeking = false;
  (String, double)? _pendingSeek;
  bool _disposed = false;

  VideoPlayerController? controllerFor(VClip c) {
    final ctl = _ctl[c.id];
    return ctl != null && ctl.value.isInitialized ? ctl : null;
  }

  double _volumeFor(VClip c) => _silent || doc.muteAll || c.mute || !c.hasAudio ? 0 : c.volume.clamp(0.0, 1.0);

  void setDoc(VideoDoc d) {
    doc = d;
    if (t > d.durationMs) t = d.durationMs;
    for (final c in d.clips) {
      final ctl = controllerFor(c);
      if (ctl != null) {
        ctl.setVolume(_volumeFor(c));
        if (playing && ctl.value.playbackSpeed != c.speed) ctl.setPlaybackSpeed(c.speed);
      }
    }
    _music?.setVolume(_silent ? 0 : (d.music?.volume ?? 0).clamp(0.0, 1.0));
    if (_musicPath != d.music?.path) _resetMusic();
    final ids = {for (final v in d.voices) v.id};
    for (final id in _voices.keys.toList()) {
      if (!ids.contains(id)) {
        _voices.remove(id)?.dispose();
        _voicePlaying.remove(id);
      }
    }
    for (final v in d.voices) {
      _voices[v.id]?.setVolume(_silent ? 0 : v.volume.clamp(0.0, 1.0));
    }
    if (playing) {
      _sync(force: true);
    } else {
      _syncPaused();
    }
    _gc();
    notifyListeners();
  }

  Future<void> _ensure(VClip c) {
    if (c.isImage) return Future.value();
    final existing = _ready[c.id];
    if (existing != null) return existing;
    final ctl = VideoPlayerController.file(File(c.path), videoPlayerOptions: VideoPlayerOptions(mixWithOthers: true));
    _ctl[c.id] = ctl;
    final f = ctl.initialize().then((_) async {
      if (_disposed) return;
      await ctl.setVolume(_volumeFor(c));
      notifyListeners();
    }).catchError((Object _) {
      // Forget the failed player so the next visit can try again.
      if (identical(_ctl[c.id], ctl)) {
        _ctl.remove(c.id);
        _ready.remove(c.id);
      }
      ctl.dispose();
    });
    _ready[c.id] = f;
    return f;
  }

  /// Keeps players only for the active clip and its neighbours.
  void _gc() {
    final keep = <String>{};
    final i = doc.indexAt(t);
    for (var k = i - 1; k <= i + 1; k++) {
      if (k >= 0 && k < doc.clips.length) keep.add(doc.clips[k].id);
    }
    for (final id in _ctl.keys.toList()) {
      if (!keep.contains(id)) {
        _ctl.remove(id)?.dispose();
        _ready.remove(id);
      }
    }
  }

  void _seekClip(VClip c, double sourceMs) {
    final ctl = controllerFor(c);
    if (ctl == null) return;
    if (_seeking) {
      _pendingSeek = (c.id, sourceMs);
      return;
    }
    _seeking = true;
    ctl.seekTo(Duration(milliseconds: sourceMs.round())).whenComplete(() {
      _seeking = false;
      final p = _pendingSeek;
      _pendingSeek = null;
      if (p != null && !_disposed) {
        final clip = doc.clips.where((x) => x.id == p.$1).firstOrNull;
        if (clip != null) _seekClip(clip, p.$2);
      }
      if (!_disposed) notifyListeners();
    });
  }

  void _syncPaused() {
    final i = doc.indexAt(t);
    active = i;
    if (i < 0) return;
    final c = doc.clips[i];
    final src = c.sourceAt(t - doc.startOf(i));
    _ensure(c).then((_) {
      if (!playing && active == i) _seekClip(c, src);
    });
  }

  /// Moves the playhead (scrubbing or tapping the ruler).
  void seek(double nt) {
    t = nt.clamp(0, doc.durationMs);
    if (playing) {
      _restartClock();
      _sync(force: true);
      _syncMusic();
      for (final id in _voicePlaying) {
        _voices[id]?.pause();
      }
      _voicePlaying.clear();
      _syncVoices();
    } else {
      _syncPaused();
      _gc();
    }
    notifyListeners();
  }

  void _restartClock() {
    _startT = t;
    _clock
      ..reset()
      ..start();
  }

  void play() {
    if (doc.clips.isEmpty || playing) return;
    if (t >= doc.durationMs - 30) t = 0;
    playing = true;
    _restartClock();
    _sync(force: true);
    _syncMusic();
    if (!_ticker.isActive) _ticker.start();
    notifyListeners();
  }

  void pause() {
    if (!playing) return;
    playing = false;
    _ticker.stop();
    _clock.stop();
    for (final ctl in _ctl.values) {
      if (ctl.value.isPlaying) ctl.pause();
    }
    _music?.pause();
    for (final id in _voicePlaying) {
      _voices[id]?.pause();
    }
    _voicePlaying.clear();
    _syncPaused();
    notifyListeners();
  }

  void toggle() => playing ? pause() : play();

  void _tick(Duration _) {
    final end = doc.durationMs;
    t = _startT + _clock.elapsedMilliseconds;
    if (t >= end) {
      t = end;
      pause();
      return;
    }
    _sync();
    _syncVoices();
    _tickMusic();
    notifyListeners();
  }

  /// Starts voice-overs as the playhead enters them and stops them as it leaves.
  void _syncVoices() {
    for (final v in doc.audibleVoices) {
      final inside = t >= v.startMs && t < v.endMs;
      final on = _voicePlaying.contains(v.id);
      if (inside && !on) {
        _voicePlaying.add(v.id);
        _startVoice(v);
      } else if (!inside && on) {
        _voicePlaying.remove(v.id);
        _voices[v.id]?.pause();
      }
    }
  }

  Future<void> _startVoice(VVoice v) async {
    var ctl = _voices[v.id];
    if (ctl == null) {
      ctl = VideoPlayerController.file(File(v.path), videoPlayerOptions: VideoPlayerOptions(mixWithOthers: true));
      _voices[v.id] = ctl;
      try {
        await ctl.initialize();
      } catch (_) {
        if (identical(_voices[v.id], ctl)) _voices.remove(v.id);
        _voicePlaying.remove(v.id);
        ctl.dispose();
        return;
      }
    }
    if (_disposed || !playing || !_voicePlaying.contains(v.id)) return;
    await ctl.setVolume(_silent ? 0 : v.volume.clamp(0.0, 1.0));
    // The playhead kept moving while the player loaded: start where it is now.
    final at = (t - v.startMs).clamp(0.0, v.durationMs);
    await ctl.seekTo(Duration(milliseconds: at.round()));
    if (playing && _voicePlaying.contains(v.id)) await ctl.play();
  }

  void _sync({bool force = false}) {
    final i = doc.indexAt(t);
    if (i < 0) return;
    final c = doc.clips[i];
    final start = doc.startOf(i);
    if (i != active || force) {
      for (final e in _ctl.entries) {
        if (e.key != c.id && e.value.value.isPlaying) e.value.pause();
      }
      active = i;
      _ensure(c).then((_) async {
        final ctl = controllerFor(c);
        if (ctl == null || !playing || active != i) return;
        await ctl.setPlaybackSpeed(c.speed);
        await ctl.setVolume(_volumeFor(c));
        await ctl.seekTo(Duration(milliseconds: c.sourceAt(t - start).round()));
        if (playing && active == i) await ctl.play();
      });
      if (i + 1 < doc.clips.length) {
        final next = doc.clips[i + 1];
        _ensure(next).then((_) {
          final n = controllerFor(next);
          if (n != null && !n.value.isPlaying) n.seekTo(Duration(milliseconds: next.inMs.round()));
        });
      }
      _gc();
      return;
    }
    final ctl = controllerFor(c);
    if (ctl == null || !ctl.value.isPlaying) return;
    // Position updates from the platform are coarse, so only correct real drift.
    final expected = c.sourceAt(t - start);
    if ((ctl.value.position.inMilliseconds - expected).abs() > 900) {
      ctl.seekTo(Duration(milliseconds: expected.round()));
    }
  }

  void _resetMusic() {
    final old = _music;
    _music = null;
    _musicPath = null;
    old?.dispose();
  }

  Future<void> _syncMusic() async {
    final m = doc.music;
    if (m == null) {
      _music?.pause();
      return;
    }
    var ctl = _music;
    if (_musicPath != m.path || ctl == null) {
      _resetMusic();
      ctl = VideoPlayerController.file(File(m.path), videoPlayerOptions: VideoPlayerOptions(mixWithOthers: true));
      _music = ctl;
      _musicPath = m.path;
      try {
        await ctl.initialize();
      } catch (_) {
        if (identical(_music, ctl)) _resetMusic();
        return;
      }
    }
    // The music may have been removed or replaced while the player was loading.
    if (_disposed || !identical(_music, ctl)) return;
    final pos = _musicPosition(m);
    if (pos == null) {
      await ctl.pause();
      return;
    }
    _musicBusy = true;
    try {
      await ctl.setVolume(_silent ? 0 : m.volume.clamp(0.0, 1.0));
      // Looping is handled here, so a loop restarts at the chosen start and not at 0:00.
      await ctl.setLooping(false);
      await ctl.seekTo(Duration(milliseconds: pos.round()));
      if (playing && identical(_music, ctl)) await ctl.play();
    } finally {
      _musicBusy = false;
    }
  }

  /// Where the song should be at the playhead, or null once a non-looping song has ended.
  double? _musicPosition(VMusic m) {
    final len = m.sourceMs - m.inMs;
    if (len <= 0) return null;
    if (!m.loop && t >= len) return null;
    return m.inMs + (m.loop ? t % len : t);
  }

  /// Keeps a looping song in step with the master clock (called every frame while playing).
  void _tickMusic() {
    final m = doc.music;
    final ctl = _music;
    if (m == null || ctl == null || !ctl.value.isInitialized || _musicBusy) return;
    final want = _musicPosition(m);
    if (want == null) {
      if (ctl.value.isPlaying) ctl.pause();
      return;
    }
    final drift = (ctl.value.position.inMilliseconds - want).abs();
    if (drift > 900 || !ctl.value.isPlaying) {
      _musicBusy = true;
      ctl.seekTo(Duration(milliseconds: want.round())).then((_) {
        if (playing && identical(_music, ctl)) return ctl.play();
      }).whenComplete(() => _musicBusy = false);
    }
  }

  bool _musicBusy = false;

  /// Frees every preview player (before an export, so the encoder gets the hardware decoders).
  void releasePlayers() {
    pause();
    for (final ctl in _ctl.values) {
      ctl.dispose();
    }
    _ctl.clear();
    _ready.clear();
    _resetMusic();
    for (final v in _voices.values) {
      v.dispose();
    }
    _voices.clear();
    _voicePlaying.clear();
  }

  /// Rebuilds the players released by [releasePlayers].
  void restorePlayers() {
    _syncPaused();
    notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    _ticker.dispose();
    for (final ctl in _ctl.values) {
      ctl.dispose();
    }
    _ctl.clear();
    _music?.dispose();
    for (final v in _voices.values) {
      v.dispose();
    }
    _voices.clear();
    super.dispose();
  }
}
