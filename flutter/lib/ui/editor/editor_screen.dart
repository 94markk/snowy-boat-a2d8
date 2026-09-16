import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:video_player/video_player.dart';

import '../../core/model/project.dart';
import '../../core/store/project_store.dart';
import '../../engine/probe.dart';
import '../common/tool_rail.dart';
import '../theme/theme.dart';
import 'editor_state.dart';
import 'filmstrip.dart';
import 'panels.dart';
import 'preview.dart';

/// The tools on the bottom rail.
const List<RailItem> editorTools = [
  RailItem('edit', 'Edit', Icons.content_cut_rounded),
  RailItem('audio', 'Audio', Icons.music_note_rounded),
  RailItem('text', 'Text', Icons.text_fields_rounded),
  RailItem('stickers', 'Stickers', Icons.emoji_emotions_rounded),
  RailItem('filters', 'Filters', Icons.photo_filter_rounded),
  RailItem('adjust', 'Adjust', Icons.tune_rounded),
  RailItem('canvas', 'Canvas', Icons.aspect_ratio_rounded),
];

/// The timeline editor.
///
/// Laid out the way phone editors are: preview on top, transport under it, the
/// timeline below that, and a scrolling rail of tools along the bottom.
/// Opening a tool covers the timeline but never the preview, so the frame
/// being graded stays on screen while it is being graded.
class EditorScreen extends StatefulWidget {
  const EditorScreen({super.key, this.projectId});

  final String? projectId;

  @override
  State<EditorScreen> createState() => _EditorScreenState();
}

class _EditorScreenState extends State<EditorScreen> {
  final EditorState _state = EditorState();
  final ImagePicker _picker = ImagePicker();

  VideoPlayerController? _controller;
  String? _controllerClipId;
  Timer? _ticker;
  Timer? _autosave;

  String? _openTool;
  bool _showExport = false;
  bool _immersive = false;

  @override
  void initState() {
    super.initState();
    _state.addListener(_onStateChanged);
    _loadProject();
    // Playback position is polled rather than driven by a listener: the
    // controller reports position changes far more often than the timeline
    // needs, and rebuilding the filmstrip at that rate wastes most of a frame.
    _ticker = Timer.periodic(const Duration(milliseconds: 60), (_) => _tick());
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _autosave?.cancel();
    _state.removeListener(_onStateChanged);
    _controller?.dispose();
    _state.dispose();
    super.dispose();
  }

  void _onStateChanged() {
    if (mounted) setState(() {});
    _scheduleAutosave();
  }

  /// Debounced: a slider drag produces a burst of states and only the settled
  /// one is worth writing to disk.
  void _scheduleAutosave() {
    _autosave?.cancel();
    if (_state.project.isEmpty) return;
    _autosave = Timer(const Duration(milliseconds: 1200), () {
      ProjectStore.save(_state.project);
    });
  }

  Future<void> _loadProject() async {
    final id = widget.projectId;
    if (id == null) return;
    final loaded = await ProjectStore.load(id);
    if (loaded == null || !mounted) return;
    _state.replaceProject(loaded);

    final missing = await ProjectStore.missingMedia(loaded);
    if (!mounted || missing.isEmpty) return;
    _state.missingMediaIds = missing;
    _say('${missing.length} clip(s) can no longer be opened.');
  }

  void _tick() {
    final controller = _controller;
    if (controller == null || !controller.value.isInitialized) return;
    if (!controller.value.isPlaying) {
      if (_state.isPlaying) _state.isPlaying = false;
      return;
    }

    _state.isPlaying = true;
    final index = _state.project.clips.indexWhere(
      (c) => c.id == _controllerClipId,
    );
    if (index < 0) return;

    final clip = _state.project.clips[index];
    final intoSource =
        controller.value.position.inMicroseconds - clip.trimStartUs;
    final onTimeline = _state.project.startOf(index) +
        (intoSource / clip.speed).round();
    _state.positionUs = onTimeline;

    // Hand over to the next clip when this one reaches its out point, so the
    // timeline plays as one piece rather than stopping at every cut.
    if (controller.value.position.inMicroseconds >= clip.trimEndUs - 40000) {
      if (index + 1 < _state.project.clips.length) {
        _seekTo(_state.project.startOf(index + 1), play: true);
      } else {
        controller.pause();
        _state.isPlaying = false;
      }
    }
  }

  /// Loads the player for whichever clip covers [timeUs], and seeks into it.
  ///
  /// One controller for the whole timeline rather than one per clip: a
  /// controller holds a decoder, and twenty of those open at once is more than
  /// a phone will give you.
  Future<void> _seekTo(int timeUs, {bool play = false}) async {
    final project = _state.project;
    if (project.clips.isEmpty) return;

    final index = project.clipIndexAt(timeUs).clamp(0, project.clips.length - 1);
    final clip = project.clips[index];
    final sourceUs = project.sourceTimeFor(index, timeUs);

    if (_controllerClipId != clip.id) {
      final old = _controller;
      _controller = null;
      _controllerClipId = null;
      await old?.dispose();

      if (clip.isImage) {
        if (mounted) setState(() {});
        return;
      }

      final next = VideoPlayerController.file(File(_localPath(clip.uri)));
      try {
        await next.initialize();
      } catch (_) {
        await next.dispose();
        _say('That clip could not be opened');
        return;
      }
      if (!mounted) {
        await next.dispose();
        return;
      }
      _controller = next;
      _controllerClipId = clip.id;
      await next.setPlaybackSpeed(clip.speed.clamp(0.25, 4.0));
      await next.setVolume(clip.muted ? 0 : clip.volume);
    }

    final controller = _controller;
    if (controller == null) return;
    await controller.seekTo(Duration(microseconds: sourceUs));
    if (play) await controller.play();
    if (mounted) setState(() {});
  }

  Future<void> _togglePlay() async {
    final controller = _controller;
    if (controller == null) {
      await _seekTo(_state.positionUs, play: true);
      return;
    }
    if (controller.value.isPlaying) {
      await controller.pause();
      _state.isPlaying = false;
    } else {
      await controller.play();
      _state.isPlaying = true;
    }
    if (mounted) setState(() {});
  }

  Future<void> _pickMedia() async {
    final files = await _picker.pickMultipleMedia();
    if (files.isEmpty) return;

    final clips = <TimelineClip>[];
    for (final file in files) {
      final clip = await MediaProbe.clipFor(file.path);
      if (clip != null) clips.add(clip);
    }

    if (clips.isNotEmpty) _state.addClips(clips);

    // Say how many failed rather than treating any failure as total failure.
    // Picking ten files and having one unsupported is a very different thing
    // from none of them loading.
    final failed = files.length - clips.length;
    if (failed > 0) {
      _say(clips.isEmpty
          ? 'Could not read those $failed file(s)'
          : 'Added ${clips.length}, skipped $failed unreadable file(s)');
    }
    if (clips.isNotEmpty) await _seekTo(_state.positionUs);
  }

  void _say(String message) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(message), behavior: SnackBarBehavior.floating),
    );
  }

  @override
  Widget build(BuildContext context) {
    final project = _state.project;
    final clip = _state.clipUnderPlayhead;
    final index = project.clips.indexWhere((c) => c.id == clip?.id);

    return Scaffold(
      backgroundColor: Shade.i900,
      body: SafeArea(
        child: Column(
          children: [
            _TopBar(
              resolutionLabel: '${_state.exportShortEdge}P',
              exportEnabled: project.clips.isNotEmpty && !_state.exporting,
              onClose: () => Navigator.of(context).maybePop(),
              onResolution: () => setState(() {
                _openTool = null;
                _showExport = true;
              }),
              onExport: () => setState(() {
                _openTool = null;
                _showExport = true;
              }),
            ),
            Expanded(
              child: project.clips.isEmpty
                  ? _EmptyCanvas(onPick: _pickMedia)
                  : EditorPreview(
                      clip: clip,
                      sourceTimeUs: index < 0
                          ? 0
                          : project.sourceTimeFor(index, _state.positionUs),
                      controller: _controller,
                      isPlaying: _state.isPlaying,
                      aspect: project.aspect,
                      backgroundColor: project.backgroundColor,
                    ),
            ),
            if (project.clips.isNotEmpty) ...[
              _TransportRow(
                isPlaying: _state.isPlaying,
                canUndo: _state.canUndo,
                canRedo: _state.canRedo,
                onPlayPause: _togglePlay,
                onUndo: () {
                  _state.undo();
                  _seekTo(_state.positionUs);
                },
                onRedo: () {
                  _state.redo();
                  _seekTo(_state.positionUs);
                },
                onFullscreen: () => setState(() => _immersive = !_immersive),
              ),
              if (!_immersive) _bottomSection(),
            ],
          ],
        ),
      ),
    );
  }

  Widget _bottomSection() {
    if (_showExport) {
      return ToolSheet(
        title: 'Export',
        onClose: () => setState(() => _showExport = false),
        child: ExportPanel(state: _state, onMessage: _say),
      );
    }

    final tool = _openTool;
    if (tool != null) {
      final item = editorTools.firstWhere((t) => t.id == tool);
      return ToolSheet(
        title: item.label,
        onClose: () => setState(() => _openTool = null),
        child: buildToolPanel(
          id: tool,
          state: _state,
          onMessage: _say,
          onSeek: () => _seekTo(_state.positionUs),
        ),
      );
    }

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Filmstrip(
          project: _state.project,
          positionUs: _state.positionUs,
          selectedClipId: _state.selectedClipId,
          onScrub: (us) => _state.positionUs = us,
          onScrubFinished: () => _seekTo(_state.positionUs),
          onSelectClip: (id) {
            _state.selectClip(id);
            _seekTo(_state.positionUs);
          },
          onAddMedia: _pickMedia,
          onAddAudio: () => setState(() => _openTool = 'audio'),
          onAddText: () => setState(() => _openTool = 'text'),
        ),
        Container(height: 1, color: Shade.i700),
        ToolRail(
          tools: editorTools,
          onSelect: (tool) => setState(() => _openTool = tool.id),
        ),
      ],
    );
  }

  static String _localPath(String uri) {
    final parsed = Uri.tryParse(uri);
    if (parsed != null && parsed.scheme == 'file') {
      return parsed.toFilePath(windows: false);
    }
    return uri;
  }
}

class _TopBar extends StatelessWidget {
  const _TopBar({
    required this.resolutionLabel,
    required this.exportEnabled,
    required this.onClose,
    required this.onResolution,
    required this.onExport,
  });

  final String resolutionLabel;
  final bool exportEnabled;
  final VoidCallback onClose;
  final VoidCallback onResolution;
  final VoidCallback onExport;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: Shade.i900,
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
      child: Row(
        children: [
          IconButton(
            onPressed: onClose,
            icon: const Icon(Icons.close_rounded, color: Mist.m200),
          ),
          const Spacer(),
          GestureDetector(
            onTap: onResolution,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
              decoration: BoxDecoration(
                color: Shade.i500,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(
                resolutionLabel,
                style: const TextStyle(color: Mist.m200, fontSize: 12),
              ),
            ),
          ),
          const SizedBox(width: 8),
          GestureDetector(
            onTap: exportEnabled ? onExport : null,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 7),
              decoration: BoxDecoration(
                color: exportEnabled ? aqua : Shade.i500,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(
                'Export',
                style: TextStyle(
                  color: exportEnabled ? Colors.black : Mist.m600,
                  fontWeight: FontWeight.bold,
                  fontSize: 12,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Play control plus the two actions that have to be reachable at all times.
///
/// Undo sits here, not behind a menu: it is what a user reaches for after a
/// change they did not intend, and burying it is what makes an editor feel
/// unsafe to experiment in.
class _TransportRow extends StatelessWidget {
  const _TransportRow({
    required this.isPlaying,
    required this.canUndo,
    required this.canRedo,
    required this.onPlayPause,
    required this.onUndo,
    required this.onRedo,
    required this.onFullscreen,
  });

  final bool isPlaying;
  final bool canUndo;
  final bool canRedo;
  final VoidCallback onPlayPause;
  final VoidCallback onUndo;
  final VoidCallback onRedo;
  final VoidCallback onFullscreen;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: Shade.i900,
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
      child: Row(
        children: [
          IconButton(
            onPressed: onFullscreen,
            icon: const Icon(Icons.fullscreen_rounded,
                size: 20, color: Mist.m400),
          ),
          const Spacer(),
          IconButton(
            onPressed: onPlayPause,
            icon: Icon(
              isPlaying ? Icons.pause_rounded : Icons.play_arrow_rounded,
              size: 28,
              color: Mist.m200,
            ),
          ),
          const Spacer(),
          IconButton(
            onPressed: canUndo ? onUndo : null,
            icon: Icon(
              Icons.undo_rounded,
              size: 20,
              color: canUndo ? Mist.m400 : Shade.i500,
            ),
          ),
          IconButton(
            onPressed: canRedo ? onRedo : null,
            icon: Icon(
              Icons.redo_rounded,
              size: 20,
              color: canRedo ? Mist.m400 : Shade.i500,
            ),
          ),
        ],
      ),
    );
  }
}

class _EmptyCanvas extends StatelessWidget {
  const _EmptyCanvas({required this.onPick});

  final VoidCallback onPick;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.movie_creation_outlined, size: 44, color: Mist.m600),
          const SizedBox(height: 14),
          const Text(
            'Nothing on the timeline yet',
            style: TextStyle(color: Mist.m400, fontSize: 14),
          ),
          const SizedBox(height: 16),
          FilledButton.icon(
            onPressed: onPick,
            icon: const Icon(Icons.add_rounded),
            label: const Text('Add photos or video'),
            style: FilledButton.styleFrom(
              backgroundColor: aqua,
              foregroundColor: Colors.black,
            ),
          ),
        ],
      ),
    );
  }
}
