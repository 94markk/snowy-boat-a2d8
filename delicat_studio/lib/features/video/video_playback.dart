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

  bool _seeking = false;
  (String, double)? _pendingSeek;
  bool _disposed = false;

  VideoPlayerController? controllerFor(VClip c) {
    final ctl = _ctl[c.id];
    return ctl != null && ctl.value.isInitialized ? ctl : null;
  }

  double _volumeFor(VClip c) => doc.muteAll || c.mute || !c.hasAudio ? 0 : c.volume.clamp(0.0, 1.0);

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
    _music?.setVolume((d.music?.volume ?? 0).clamp(0.0, 1.0));
    if (_musicPath != d.music?.path) _resetMusic();
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
    }).catchError((Object _) {});
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
    notifyListeners();
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
    if (_musicPath != m.path) {
      _resetMusic();
      final ctl = VideoPlayerController.file(File(m.path), videoPlayerOptions: VideoPlayerOptions(mixWithOthers: true));
      _music = ctl;
      _musicPath = m.path;
      try {
        await ctl.initialize();
      } catch (_) {
        return;
      }
    }
    final ctl = _music!;
    final len = m.sourceMs - m.inMs;
    if (len <= 0) return;
    if (!m.loop && t >= len) {
      await ctl.pause();
      return;
    }
    final pos = m.inMs + (m.loop ? t % len : t);
    await ctl.setVolume(m.volume.clamp(0.0, 1.0));
    await ctl.setLooping(m.loop);
    await ctl.seekTo(Duration(milliseconds: pos.round()));
    if (playing) await ctl.play();
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
    super.dispose();
  }
}
