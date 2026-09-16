import 'dart:io';

import 'package:flutter/material.dart';

import '../../core/model/project.dart';
import '../../engine/thumbnails.dart';
import '../theme/theme.dart';
import 'time_format.dart';

const double _clipRowHeight = 54;
const double _trackRowHeight = 28;
const double _rulerHeight = 20;

const double _minDpPerSecond = 6;
const double _maxDpPerSecond = 200;
const double _defaultDpPerSecond = 34;

/// The timeline, built the way a phone editor has to be: the playhead is fixed
/// at the centre and the media scrolls underneath it.
///
/// Dragging a playhead along a static strip does not work at this size. The
/// finger covers the frame it is aiming at, and the end of the timeline sits
/// under the screen edge where it cannot be grabbed. Pinning the playhead puts
/// the current frame in the middle of the screen, directly below the preview
/// showing that same frame, and lets a clip be trimmed to its final frame.
class Filmstrip extends StatefulWidget {
  const Filmstrip({
    super.key,
    required this.project,
    required this.positionUs,
    required this.selectedClipId,
    required this.onScrub,
    required this.onScrubFinished,
    required this.onSelectClip,
    required this.onAddMedia,
    required this.onAddAudio,
    required this.onAddText,
  });

  final Project project;
  final int positionUs;
  final String? selectedClipId;
  final ValueChanged<int> onScrub;
  final VoidCallback onScrubFinished;
  final ValueChanged<String> onSelectClip;
  final VoidCallback onAddMedia;
  final VoidCallback onAddAudio;
  final VoidCallback onAddText;

  @override
  State<Filmstrip> createState() => _FilmstripState();
}

class _FilmstripState extends State<Filmstrip> {
  final ScrollController _controller = ScrollController();
  double _dpPerSecond = _defaultDpPerSecond;

  /// True only while the user's own drag, and the fling after it, owns the
  /// strip. Scroll position alone cannot tell the two apart: following
  /// playback also moves the scroll offset, so without this the playhead would
  /// drive a scroll that was read straight back as a scrub, seeking the player
  /// against itself several times a second.
  bool _userDriven = false;

  double _zoomAnchor = _defaultDpPerSecond;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(Filmstrip old) {
    super.didUpdateWidget(old);
    if (old.positionUs != widget.positionUs) _syncScrollToPlayhead();
  }

  void _syncScrollToPlayhead() {
    if (_userDriven || !_controller.hasClients) return;
    final target = _offsetForTime(widget.positionUs)
        .clamp(0.0, _controller.position.maxScrollExtent);
    if ((_controller.offset - target).abs() > 1) {
      _controller.jumpTo(target);
    }
  }

  double _offsetForTime(int timeUs) => timeUs / 1000000 * _dpPerSecond;

  int _timeForOffset(double offset) =>
      _dpPerSecond <= 0 ? 0 : (offset / _dpPerSecond * 1000000).round();

  bool _onNotification(ScrollNotification notification) {
    if (notification is ScrollStartNotification) {
      // dragDetails is non-null only for a touch-initiated scroll, which is
      // exactly the distinction that matters here.
      if (notification.dragDetails != null) _userDriven = true;
    } else if (notification is ScrollUpdateNotification) {
      if (_userDriven) {
        widget.onScrub(
          _timeForOffset(_controller.offset)
              .clamp(0, widget.project.durationUs),
        );
      }
    } else if (notification is ScrollEndNotification) {
      // The fling keeps running after the finger lifts, so the settle is taken
      // from the scroll ending rather than from the touch ending.
      if (_userDriven) {
        _userDriven = false;
        widget.onScrubFinished();
      }
    }
    return false;
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final leadIn = constraints.maxWidth / 2;

        return Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            _TimeReadout(
              positionUs: widget.positionUs,
              durationUs: widget.project.durationUs,
            ),
            Stack(
              alignment: Alignment.topCenter,
              children: [
                GestureDetector(
                  onScaleStart: (_) => _zoomAnchor = _dpPerSecond,
                  onScaleUpdate: (details) {
                    // Only react to a genuine pinch. A one-finger drag reports
                    // scale 1, and reacting to it would fight the scroll view
                    // for the same gesture.
                    if (details.pointerCount < 2) return;
                    setState(() {
                      _dpPerSecond = (_zoomAnchor * details.scale)
                          .clamp(_minDpPerSecond, _maxDpPerSecond);
                    });
                    _syncScrollToPlayhead();
                  },
                  child: NotificationListener<ScrollNotification>(
                    onNotification: _onNotification,
                    child: SingleChildScrollView(
                      controller: _controller,
                      scrollDirection: Axis.horizontal,
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          _Ruler(
                            durationUs: widget.project.durationUs,
                            dpPerSecond: _dpPerSecond,
                            leadIn: leadIn,
                          ),
                          _ClipRow(
                            project: widget.project,
                            selectedClipId: widget.selectedClipId,
                            dpPerSecond: _dpPerSecond,
                            leadIn: leadIn,
                            onSelectClip: widget.onSelectClip,
                            onAddMedia: widget.onAddMedia,
                          ),
                          _AudioTrack(
                            project: widget.project,
                            dpPerSecond: _dpPerSecond,
                            leadIn: leadIn,
                            onAddAudio: widget.onAddAudio,
                          ),
                          _TextTrack(
                            project: widget.project,
                            dpPerSecond: _dpPerSecond,
                            leadIn: leadIn,
                            onAddText: widget.onAddText,
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                const _Playhead(),
              ],
            ),
          ],
        );
      },
    );
  }
}

class _TimeReadout extends StatelessWidget {
  const _TimeReadout({required this.positionUs, required this.durationUs});

  final int positionUs;
  final int durationUs;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 3),
      child: Row(
        children: [
          Text(
            formatTimePrecise(positionUs),
            style: Theme.of(context)
                .textTheme
                .labelSmall
                ?.copyWith(color: Mist.m200),
          ),
          Text(
            ' / ${formatTime(durationUs)}',
            style: Theme.of(context)
                .textTheme
                .labelSmall
                ?.copyWith(color: Mist.m400),
          ),
        ],
      ),
    );
  }
}

/// Tick labels, spaced by an interval chosen from the current zoom.
///
/// A fixed interval breaks at both ends: every second smears into a grey band
/// when zoomed out, every thirty leaves the ruler blank when zoomed in.
class _Ruler extends StatelessWidget {
  const _Ruler({
    required this.durationUs,
    required this.dpPerSecond,
    required this.leadIn,
  });

  final int durationUs;
  final double dpPerSecond;
  final double leadIn;

  int get _step {
    if (dpPerSecond >= 110) return 1;
    if (dpPerSecond >= 55) return 2;
    if (dpPerSecond >= 28) return 5;
    if (dpPerSecond >= 14) return 10;
    return 30;
  }

  @override
  Widget build(BuildContext context) {
    final total = durationUs ~/ 1000000;
    final step = _step;
    final ticks = <Widget>[SizedBox(width: leadIn)];

    for (var second = 0; second <= total + step; second += step) {
      ticks.add(
        SizedBox(
          width: dpPerSecond * step,
          child: Text(
            formatTime(second * 1000000),
            maxLines: 1,
            style: Theme.of(context)
                .textTheme
                .labelSmall
                ?.copyWith(color: Mist.m400),
          ),
        ),
      );
    }
    ticks.add(SizedBox(width: leadIn));

    return SizedBox(
      height: _rulerHeight,
      child: Row(crossAxisAlignment: CrossAxisAlignment.center, children: ticks),
    );
  }
}

class _ClipRow extends StatelessWidget {
  const _ClipRow({
    required this.project,
    required this.selectedClipId,
    required this.dpPerSecond,
    required this.leadIn,
    required this.onSelectClip,
    required this.onAddMedia,
  });

  final Project project;
  final String? selectedClipId;
  final double dpPerSecond;
  final double leadIn;
  final ValueChanged<String> onSelectClip;
  final VoidCallback onAddMedia;

  @override
  Widget build(BuildContext context) {
    final children = <Widget>[SizedBox(width: leadIn)];

    for (var i = 0; i < project.clips.length; i++) {
      final clip = project.clips[i];
      if (i > 0) {
        children.add(_TransitionMarker(active: clip.transition.isActive));
      }
      children.add(
        _ClipCell(
          clip: clip,
          selected: clip.id == selectedClipId,
          // A floor keeps a very short cut tappable rather than a hairline the
          // finger cannot land on.
          width: (clip.timelineDurationUs / 1000000 * dpPerSecond)
              .clamp(30.0, double.infinity),
          onTap: () => onSelectClip(clip.id),
        ),
      );
    }

    children
      ..add(_AddMediaButton(onTap: onAddMedia))
      ..add(SizedBox(width: leadIn));

    return SizedBox(
      height: _clipRowHeight,
      child: Row(children: children),
    );
  }
}

class _ClipCell extends StatelessWidget {
  const _ClipCell({
    required this.clip,
    required this.selected,
    required this.width,
    required this.onTap,
  });

  final Clip clip;
  final bool selected;
  final double width;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        width: width,
        margin: const EdgeInsets.symmetric(horizontal: 1),
        decoration: BoxDecoration(
          color: Shade.i600,
          borderRadius: BorderRadius.circular(6),
          border: selected ? Border.all(color: aqua, width: 2) : null,
        ),
        // ClipRRect rather than clipBehavior: our Clip model shadows the
        // Flutter enum of the same name, so Clip.antiAlias will not resolve
        // inside this library.
        child: ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: Stack(
            fit: StackFit.expand,
            children: [
              _ClipThumbnails(clip: clip, width: width),
              if (clip.muted || clip.volume == 0)
                const Positioned(
                  left: 3,
                  top: 3,
                  child: Icon(Icons.volume_off_rounded,
                      size: 12, color: Mist.m200),
                ),
            if (clip.speed != 1)
              Positioned(
                right: 2,
                bottom: 2,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 3),
                  color: const Color(0x99000000),
                  child: Text(
                    '${clip.speed}x',
                    style: const TextStyle(fontSize: 9, color: Mist.m200),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Frames along the length of a clip.
///
/// The cells are laid out first and the images fill in when the strip arrives,
/// so the timeline has its final shape immediately. Building the layout from
/// decoded frames instead would make it jump around while they load.
class _ClipThumbnails extends StatefulWidget {
  const _ClipThumbnails({required this.clip, required this.width});

  final Clip clip;
  final double width;

  @override
  State<_ClipThumbnails> createState() => _ClipThumbnailsState();
}

class _ClipThumbnailsState extends State<_ClipThumbnails> {
  List<String> _frames = const [];

  int get _count =>
      (widget.width / (_clipRowHeight * 16 / 9)).ceil().clamp(1, 24);

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(_ClipThumbnails old) {
    super.didUpdateWidget(old);
    final clip = widget.clip;
    if (old.clip.uri != clip.uri ||
        old.clip.trimStartUs != clip.trimStartUs ||
        old.clip.trimEndUs != clip.trimEndUs ||
        _frames.length != _count) {
      _load();
    }
  }

  Future<void> _load() async {
    final frames = await Thumbnailer.strip(widget.clip, count: _count);
    if (mounted) setState(() => _frames = frames);
  }

  @override
  Widget build(BuildContext context) {
    if (_frames.isEmpty) return const SizedBox.shrink();
    final cellWidth = widget.width / _frames.length;

    return Row(
      children: [
        for (final path in _frames)
          SizedBox(
            width: cellWidth,
            height: _clipRowHeight,
            child: Image.file(
              File(path),
              fit: BoxFit.cover,
              gaplessPlayback: true,
              // A frame that fails to decode leaves the block plain rather
              // than showing a broken-image glyph on the timeline.
              errorBuilder: (_, _, _) => const SizedBox.shrink(),
            ),
          ),
      ],
    );
  }
}

class _TransitionMarker extends StatelessWidget {
  const _TransitionMarker({required this.active});

  final bool active;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 15,
      height: 15,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: active ? aqua : Shade.i500,
        borderRadius: BorderRadius.circular(3),
      ),
      child: Icon(
        Icons.swap_horiz_rounded,
        size: 10,
        color: active ? Colors.black : Mist.m400,
      ),
    );
  }
}

class _AddMediaButton extends StatelessWidget {
  const _AddMediaButton({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        width: _clipRowHeight,
        height: _clipRowHeight,
        margin: const EdgeInsets.symmetric(horizontal: 4),
        decoration: BoxDecoration(
          color: Shade.i500,
          borderRadius: BorderRadius.circular(6),
        ),
        child: const Icon(Icons.add_rounded, color: Mist.m200),
      ),
    );
  }
}

class _AudioTrack extends StatelessWidget {
  const _AudioTrack({
    required this.project,
    required this.dpPerSecond,
    required this.leadIn,
    required this.onAddAudio,
  });

  final Project project;
  final double dpPerSecond;
  final double leadIn;
  final VoidCallback onAddAudio;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: _trackRowHeight,
      child: Row(
        children: [
          SizedBox(width: leadIn),
          if (project.audio.isEmpty)
            _TrackPlaceholder(
              icon: Icons.music_note_rounded,
              label: 'Add audio',
              onTap: onAddAudio,
            )
          else
            for (final track in project.audio)
              _TrackChip(
                label: track.title.isEmpty ? 'Audio' : track.title,
                width: (track.timelineDurationUs / 1000000 * dpPerSecond)
                    .clamp(30.0, double.infinity),
                color: const Color(0xFF1E4D3F),
              ),
          SizedBox(width: leadIn),
        ],
      ),
    );
  }
}

class _TextTrack extends StatelessWidget {
  const _TextTrack({
    required this.project,
    required this.dpPerSecond,
    required this.leadIn,
    required this.onAddText,
  });

  final Project project;
  final double dpPerSecond;
  final double leadIn;
  final VoidCallback onAddText;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: _trackRowHeight,
      child: Row(
        children: [
          SizedBox(width: leadIn),
          if (project.overlays.isEmpty)
            _TrackPlaceholder(
              icon: Icons.text_fields_rounded,
              label: 'Add text',
              onTap: onAddText,
            )
          else
            for (final overlay in project.overlays)
              _TrackChip(
                label: overlay.trackLabel,
                width: (overlay.durationUs / 1000000 * dpPerSecond)
                    .clamp(30.0, double.infinity),
                color: const Color(0xFF3A2F5C),
              ),
          SizedBox(width: leadIn),
        ],
      ),
    );
  }
}

class _TrackChip extends StatelessWidget {
  const _TrackChip({
    required this.label,
    required this.width,
    required this.color,
  });

  final String label;
  final double width;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: _trackRowHeight - 6,
      margin: const EdgeInsets.symmetric(horizontal: 1),
      padding: const EdgeInsets.symmetric(horizontal: 5),
      alignment: Alignment.centerLeft,
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(
        label,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: const TextStyle(fontSize: 10, color: Mist.m200),
      ),
    );
  }
}

class _TrackPlaceholder extends StatelessWidget {
  const _TrackPlaceholder({
    required this.icon,
    required this.label,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        height: _trackRowHeight - 6,
        padding: const EdgeInsets.symmetric(horizontal: 10),
        decoration: BoxDecoration(
          color: Shade.i700,
          borderRadius: BorderRadius.circular(4),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 14, color: Mist.m400),
            const SizedBox(width: 6),
            Text(label,
                style: const TextStyle(fontSize: 10, color: Mist.m400)),
          ],
        ),
      ),
    );
  }
}

class _Playhead extends StatelessWidget {
  const _Playhead();

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 9,
            height: 9,
            decoration: BoxDecoration(
              color: aqua,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          Container(
            width: 2,
            height: _rulerHeight + _clipRowHeight + _trackRowHeight * 2 - 9,
            color: aqua,
          ),
        ],
      ),
    );
  }
}
