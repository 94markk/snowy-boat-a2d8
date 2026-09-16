import 'package:flutter/foundation.dart';

import '../../core/model/adjustments.dart';
import '../../core/model/overlay.dart';
import '../../core/model/project.dart';

/// Timeline editing state.
///
/// The project itself is immutable and every edit swaps in a new one, which
/// makes undo a matter of keeping the old references rather than replaying
/// inverse operations.
class EditorState extends ChangeNotifier {
  Project _project = Project();
  Project get project => _project;

  String? _selectedClipId;
  String? get selectedClipId => _selectedClipId;

  String? _selectedOverlayId;
  String? get selectedOverlayId => _selectedOverlayId;

  /// Playhead, in microseconds from the start of the timeline.
  int _positionUs = 0;
  int get positionUs => _positionUs;

  bool _isPlaying = false;
  bool get isPlaying => _isPlaying;

  bool _exporting = false;
  bool get exporting => _exporting;

  double _exportProgress = 0;
  double get exportProgress => _exportProgress;

  /// Export settings live here, not in the export panel, so the top bar can
  /// show the target the file will actually be written at. Held in the panel
  /// they reset every time it closed and the header had nothing to read.
  int exportShortEdge = 1080;
  int exportFps = 30;

  Set<String> missingMediaIds = {};

  final List<Project> _undoStack = [];
  final List<Project> _redoStack = [];
  Project? _gestureSnapshot;

  bool get canUndo => _undoStack.isNotEmpty;
  bool get canRedo => _redoStack.isNotEmpty;

  TimelineClip? get selectedClip {
    final id = _selectedClipId;
    if (id == null) return null;
    for (final clip in _project.clips) {
      if (clip.id == id) return clip;
    }
    return null;
  }

  TimelineClip? get clipUnderPlayhead {
    if (_project.clips.isEmpty) return null;
    final index = _project.clipIndexAt(_positionUs);
    if (index < 0) return _project.clips.first;
    return _project.clips[index];
  }

  /// The clip the tools act on, and the clip the preview grades with.
  ///
  /// Falling back to whatever sits under the playhead is what makes the
  /// controls work at all. Addressing edits to the selection alone means they
  /// are dropped whenever nothing is selected, so a slider moves, the frame
  /// stays put, and the app looks like none of its parameters are connected to
  /// anything. That was the original complaint about this editor.
  TimelineClip? get activeClip => selectedClip ?? clipUnderPlayhead;

  String? get activeClipId => activeClip?.id;

  Overlay? get selectedOverlay {
    final id = _selectedOverlayId;
    if (id == null) return null;
    for (final overlay in _project.overlays) {
      if (overlay.id == id) return overlay;
    }
    return null;
  }

  set positionUs(int value) {
    final clamped = value.clamp(0, _project.durationUs.clamp(0, 1 << 62));
    if (clamped == _positionUs) return;
    _positionUs = clamped;
    notifyListeners();
  }

  set isPlaying(bool value) {
    if (value == _isPlaying) return;
    _isPlaying = value;
    notifyListeners();
  }

  void setExporting(bool value, {double progress = 0}) {
    _exporting = value;
    _exportProgress = progress;
    notifyListeners();
  }

  void setExportProgress(double value) {
    _exportProgress = value.clamp(0.0, 1.0);
    notifyListeners();
  }

  /// Selects [id] and brings the playhead into that clip.
  ///
  /// Editing a clip you cannot see is the same bug as editing nothing: the
  /// change lands and still looks ignored. Moving the playhead keeps the frame
  /// on screen and the clip being edited the same one.
  void selectClip(String? id) {
    _selectedClipId = id;
    if (id != null) {
      _selectedOverlayId = null;
      final index = _project.clips.indexWhere((c) => c.id == id);
      if (index >= 0) {
        final start = _project.startOf(index);
        final end = start + _project.clips[index].timelineDurationUs;
        if (_positionUs < start || _positionUs >= end) _positionUs = start;
      }
    }
    notifyListeners();
  }

  void selectOverlay(String? id) {
    _selectedOverlayId = id;
    notifyListeners();
  }

  void replaceProject(Project next) {
    _project = next;
    _undoStack.clear();
    _redoStack.clear();
    _gestureSnapshot = null;
    _selectedClipId = next.clips.isEmpty ? null : next.clips.first.id;
    _selectedOverlayId = null;
    _positionUs = 0;
    notifyListeners();
  }

  /// Live edit during a drag; no undo entry of its own.
  ///
  /// A slider drag produces a burst of states, and recording each one would
  /// make undo step back through a hundred intermediate values instead of
  /// returning to where the drag started.
  void edit(Project Function(Project) transform) {
    _project = transform(_project);
    notifyListeners();
  }

  /// Discrete edit; gets its own undo entry.
  void commit(Project Function(Project) transform) {
    final previous = _project;
    final next = transform(previous);
    if (identical(next, previous)) return;
    _push(previous);
    _project = next;
    notifyListeners();
  }

  void beginGesture() {
    _gestureSnapshot ??= _project;
  }

  void endGesture() {
    final snapshot = _gestureSnapshot;
    _gestureSnapshot = null;
    if (snapshot == null) return;
    if (!identical(snapshot, _project)) {
      _push(snapshot);
      notifyListeners();
    }
  }

  void _push(Project previous) {
    _undoStack.add(previous);
    // A bounded history: an editing session can run for hours, and keeping
    // every state would grow without limit for a feature nobody reaches the
    // bottom of.
    if (_undoStack.length > 60) _undoStack.removeAt(0);
    _redoStack.clear();
  }

  void undo() {
    if (_undoStack.isEmpty) return;
    _redoStack.add(_project);
    _project = _undoStack.removeLast();
    _clampAfterStructureChange();
    notifyListeners();
  }

  void redo() {
    if (_redoStack.isEmpty) return;
    _undoStack.add(_project);
    _project = _redoStack.removeLast();
    _clampAfterStructureChange();
    notifyListeners();
  }

  void _clampAfterStructureChange() {
    if (!_project.clips.any((c) => c.id == _selectedClipId)) {
      _selectedClipId = _project.clips.isEmpty ? null : _project.clips.first.id;
    }
    _positionUs = _positionUs.clamp(0, _project.durationUs.clamp(0, 1 << 62));
  }

  /// Appends [clips], and on the first import shapes the canvas to fit them.
  ///
  /// The canvas stays put on every later import, because by then it is a
  /// choice the user has made, possibly one the earlier clips have already
  /// been cropped to suit. Only the empty-project case is a guess worth making.
  void addClips(List<TimelineClip> clips) {
    if (clips.isEmpty) return;
    final first = clips.first;
    final wasEmpty = _project.clips.isEmpty;

    commit((current) {
      final next = current.copyWith(clips: [...current.clips, ...clips]);
      if (!wasEmpty) return next;
      return next.copyWith(
        aspect: CanvasRatio.closestTo(first.displayWidth, first.displayHeight),
      );
    });

    _selectedClipId ??= first.id;
    notifyListeners();
  }

  void addAudio(AudioClip track) =>
      commit((p) => p.copyWith(audio: [...p.audio, track]));

  void addOverlay(Overlay overlay) {
    commit((p) => p.addOverlay(overlay));
    _selectedOverlayId = overlay.id;
    notifyListeners();
  }

  void updateSelectedOverlay(Overlay Function(Overlay) transform) {
    final id = _selectedOverlayId;
    if (id == null) return;
    edit((p) => p.updateOverlay(id, transform));
  }

  void commitSelectedOverlay(Overlay Function(Overlay) transform) {
    final id = _selectedOverlayId;
    if (id == null) return;
    commit((p) => p.updateOverlay(id, transform));
  }

  void removeSelectedOverlay() {
    final id = _selectedOverlayId;
    if (id == null) return;
    commit((p) => p.removeOverlay(id));
    _selectedOverlayId =
        _project.overlays.isEmpty ? null : _project.overlays.last.id;
    notifyListeners();
  }

  void updateActiveClip(TimelineClip Function(TimelineClip) transform) {
    final id = activeClipId;
    if (id == null) return;
    edit((p) => p.updateClip(id, transform));
  }

  void commitActiveClip(TimelineClip Function(TimelineClip) transform) {
    final id = activeClipId;
    if (id == null) return;
    commit((p) => p.updateClip(id, transform));
  }

  /// Sets one adjustment on the active clip.
  ///
  /// [live] distinguishes a value arriving mid-drag from the one the user
  /// settled on: mid-drag values must not each become their own undo entry, or
  /// undo walks back through every intermediate position instead of returning
  /// to where the drag began.
  void setAdjustment(String specId, double value, {bool live = true}) {
    TimelineClip apply(TimelineClip c) =>
        c.copyWith(adjustments: c.adjustments.set(specId, value));
    if (live) {
      updateActiveClip(apply);
    } else {
      commitActiveClip(apply);
    }
  }

  Adjustments get activeAdjustments =>
      activeClip?.adjustments ?? const Adjustments();

  void removeActiveClip() {
    final id = activeClipId;
    if (id == null) return;
    commit((p) => p.removeClip(id));
    _selectedClipId = _project.clips.isEmpty ? null : _project.clips.first.id;
    _positionUs = _positionUs.clamp(0, _project.durationUs.clamp(0, 1 << 62));
    notifyListeners();
  }

  void duplicateActiveClip() {
    final id = activeClipId;
    if (id == null) return;
    commit((p) => p.duplicateClip(id));
  }

  void splitAtPlayhead() => commit((p) => p.splitAt(_positionUs));

  void moveActiveClip(int offset) {
    final id = activeClipId;
    if (id == null) return;
    final from = _project.clips.indexWhere((c) => c.id == id);
    if (from < 0) return;
    final to = (from + offset).clamp(0, _project.clips.length - 1);
    commit((p) => p.moveClip(from, to));
  }

  void rename(String name) => commit((p) => p.copyWith(name: name));

  void setAspect(CanvasRatio aspect) => commit((p) => p.copyWith(aspect: aspect));
}
